<?php

namespace Shirahcan\CalendarClient\Laravel\Spec;

use Carbon\CarbonImmutable;

/**
 * A product's availability rows as calendar-service's schema-1 spec (K5). Every product turns its
 * own rule rows into NORMALIZED rows (below) and hands them here; the mapping itself exists once.
 *
 * A normalized row:
 *
 *   kind     'weekly' | 'date' (an override for one date) | 'block'
 *   day      0 (Sunday) .. 6, weekly only
 *   date     'Y-m-d' (date rows; a block's first day)
 *   to       'Y-m-d', a block's last day (defaults to `date`)
 *   start    'HH:MM' | null       end 'HH:MM' | null ('00:00' as an end = midnight at day's end)
 *   from     'Y-m-d' | null       until 'Y-m-d' | null   (a row's own validity)
 *   period   int | null          (the availability period it belongs to, when periods are on)
 *   tz       IANA zone | null    (the zone the row's clock times are in)
 *   duration int | null           buffer int | null (minutes)
 *
 * Options (each one a real difference between products, made explicit):
 *
 *   zone               fallback zone when no timed row names one (the person's profile zone)
 *   periods_on, periods  [id => ['from' => 'Y-m-d', 'to' => ?'Y-m-d']]: when on, a weekly row
 *                       counts only inside its period; a period-less weekly row counts nowhere
 *   cross_midnight     true: a window ending at/before its start runs past midnight (Portify);
 *                       false: it is skipped, as an engine that drops it does (MployNow)
 *   empty_date_closes  true: a date whose override rows carry no times is CLOSED (Portify)
 *   min_buffer         floor for the buffer (Portify's engine never goes below 5)
 *   today              for the past cutoff (tests)
 *
 * Returns ['spec' => ?array, 'duration' => int (the most common), 'buffer' => int (the largest),
 * 'problems' => list (spec is null when there are any), 'notes' => list].
 *
 * ⚠ One spec has ONE zone. Timed rows in different zones are never relabelled into one (that
 * silently moves real hours): the result is the problem `mixed_zones` and no spec.
 */
final class SpecBuilder
{
    /** Past dates are dropped; two days of slack covers every zone. */
    public const PAST_DAYS_KEPT = 2;

    private const DAYS = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

    public static function build(array $rows, array $options = []): array
    {
        $o = $options + [
            'zone' => 'UTC', 'periods_on' => false, 'periods' => [], 'cross_midnight' => false,
            'empty_date_closes' => false, 'min_buffer' => 0, 'today' => null,
        ];
        $cutoff = ($o['today'] ?? CarbonImmutable::now('UTC'))->setTimezone('UTC')->subDays(self::PAST_DAYS_KEPT)->format('Y-m-d');
        $rows = array_map(fn ($r) => $r + ['day' => null, 'date' => null, 'to' => null, 'start' => null, 'end' => null,
            'from' => null, 'until' => null, 'period' => null, 'tz' => null, 'duration' => null, 'buffer' => null], $rows);

        // History never shapes today's spec: not its windows, not its buffer, not its zone.
        $current = array_values(array_filter($rows, fn ($r) => match ($r['kind']) {
            'weekly' => $r['until'] === null || $r['until'] >= $cutoff,
            'block' => max((string) ($r['to'] ?? $r['date']), (string) $r['date']) >= $cutoff,
            default => $r['date'] !== null && $r['date'] >= $cutoff,
        }));

        $notes = [];
        $timed = array_values(array_filter($current, fn ($r) => $r['kind'] !== 'block' && $r['start'] !== null && $r['end'] !== null));

        $durations = array_values(array_filter(array_map(fn ($r) => $r['duration'], $timed)));
        $duration = $durations === [] ? 30 : self::mode($durations);
        if (count(array_unique($durations)) > 1) {
            $notes[] = 'mixed_durations:'.implode(',', self::sortedUnique($durations));
        }

        $weeklyBuffers = array_map(fn ($r) => max((int) $r['buffer'], (int) $o['min_buffer']), array_filter($timed, fn ($r) => $r['kind'] === 'weekly'));
        if (count(array_unique($weeklyBuffers)) > 1) {
            $notes[] = 'mixed_buffers:'.implode(',', self::sortedUnique($weeklyBuffers));
        }
        $buffer = max((int) $o['min_buffer'], ...array_map(fn ($r) => (int) $r['buffer'], $timed === [] ? [['buffer' => 0]] : $timed));

        // Only a row with clock times is read in its zone: a full-day block names a DATE.
        $zones = self::sortedUnique(array_values(array_filter(array_map(fn ($r) => $r['tz'], array_filter($current,
            fn ($r) => $r['start'] !== null || $r['end'] !== null)))));
        if (count($zones) > 1) {
            return ['spec' => null, 'duration' => $duration, 'buffer' => $buffer, 'problems' => ['mixed_zones:'.implode(',', $zones)], 'notes' => $notes];
        }
        $zone = $zones[0] ?? $o['zone'];
        if ($zones !== [] && $zone !== $o['zone']) {
            $notes[] = "rules_zone_differs_from_profile:{$zone}!={$o['zone']}";
        }

        $spec = ['schema' => 1, 'timezone' => ['zone' => $zone]];
        $weekly = $overrides = $blocks = [];

        foreach ($current as $r) {
            if ($r['kind'] === 'block') {
                $block = self::block($r);
                if ($block !== null) {
                    $blocks[] = $block;
                }

                continue;
            }

            $window = self::window($r['start'], $r['end'], (bool) $o['cross_midnight']);

            if ($r['kind'] === 'weekly') {
                [$from, $until] = [$r['from'], $r['until']];
                if ($o['periods_on']) {
                    $p = $r['period'] === null ? null : ($o['periods'][$r['period']] ?? null);
                    if ($p === null) {
                        continue;   // a period-less weekly row counts nowhere while periods are on
                    }
                    $from = self::later($from, $p['from'] ?? null);
                    $until = self::earlier($until, $p['to'] ?? null);
                }
                if ($window === null || $r['day'] === null || ! isset(self::DAYS[(int) $r['day']])
                    || ($until !== null && $until < $cutoff) || ($from !== null && $until !== null && $from > $until)) {
                    continue;
                }
                $weekly[] = array_filter(['days' => [self::DAYS[(int) $r['day']]], 'start' => $window[0], 'end' => $window[1],
                    'valid_from' => $from, 'valid_until' => $until], fn ($v) => $v !== null);

                continue;
            }

            // A date override: in force on its own date, within its validity, inside its period.
            $date = $r['date'];
            if (($r['from'] && $date < $r['from']) || ($r['until'] && $date > $r['until'])) {
                continue;
            }
            if ($o['periods_on'] && $r['period'] !== null) {
                $p = $o['periods'][$r['period']] ?? null;
                if ($p === null || $date < $p['from'] || (($p['to'] ?? null) !== null && $date > $p['to'])) {
                    continue;
                }
            }
            if ($window !== null) {
                $overrides[$date][] = $window;
            } elseif ($o['empty_date_closes']) {
                $overrides[$date] ??= [];
            }
        }

        if ($weekly !== []) {
            usort($weekly, fn ($a, $b) => strcmp($a['days'][0].$a['start'].$a['end'].($a['valid_from'] ?? ''), $b['days'][0].$b['start'].$b['end'].($b['valid_from'] ?? '')));
            $spec['weekly'] = array_values($weekly);
        }
        if ($overrides !== []) {
            ksort($overrides);
            $spec['overrides'] = array_map(fn ($date, $w) => ['date' => $date, 'windows' => self::merge($w)], array_keys($overrides), $overrides);
        }
        if ($blocks !== []) {
            $blocks = array_values(array_unique($blocks, SORT_REGULAR));
            usort($blocks, fn ($a, $b) => strcmp(($a['from'] ?? $a['start']).json_encode($a), ($b['from'] ?? $b['start']).json_encode($b)));
            $spec['blocks'] = $blocks;
        }

        return ['spec' => $spec, 'duration' => $duration, 'buffer' => $buffer, 'problems' => [], 'notes' => $notes];
    }

    /** A whole-day span, or a timed block on one date (an end at/before the start runs into the next day). */
    private static function block(array $r): ?array
    {
        if ($r['date'] === null) {
            return null;
        }
        if ($r['start'] === null || $r['end'] === null) {
            return ['from' => $r['date'], 'to' => max((string) ($r['to'] ?? $r['date']), (string) $r['date'])];
        }
        $start = substr($r['start'], 0, 5);
        $end = substr($r['end'], 0, 5);
        $endDate = ($end === '00:00' || $end === '24:00' || self::minutes($end) <= self::minutes($start))
            ? CarbonImmutable::parse($r['date'])->addDay()->format('Y-m-d')
            : $r['date'];

        return ['start' => "{$r['date']}T{$start}", 'end' => $endDate.'T'.($end === '24:00' ? '00:00' : $end)];
    }

    /** "HH:MM" pair; '00:00' as an end is 24:00. Null when the row has no usable window. */
    private static function window(?string $start, ?string $end, bool $crossMidnight): ?array
    {
        if ($start === null || $end === null) {
            return null;
        }
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);
        if ($end === '00:00') {
            $end = '24:00';
        }
        if (! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end) || $start === $end) {
            return null;
        }
        if (self::minutes($end) <= self::minutes($start) && ! $crossMidnight) {
            return null;
        }

        return [$start, $end];
    }

    /** Union a date's windows: the spec refuses overlapping windows on one date. */
    private static function merge(array $windows): array
    {
        if ($windows === []) {
            return [];
        }
        $ranges = array_map(function ($w) {
            $s = self::minutes($w[0]);
            $e = self::minutes($w[1]);

            return [$s, $e <= $s ? $e + 1440 : $e];
        }, $windows);
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($ranges as $r) {
            $last = count($merged) - 1;
            if ($last >= 0 && $r[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $r[1]);
            } else {
                $merged[] = $r;
            }
        }
        $fmt = fn (int $m) => $m === 1440 ? '24:00' : sprintf('%02d:%02d', intdiv($m % 1440, 60), $m % 60);

        return array_map(fn ($r) => $r[1] - $r[0] >= 1440 ? ['00:00', '24:00'] : [$fmt($r[0]), $fmt($r[1])], $merged);
    }

    private static function minutes(string $hhmm): int
    {
        if ($hhmm === '24:00') {
            return 1440;
        }
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    /** The most common value; ties go to the smallest (stable). */
    private static function mode(array $values): int
    {
        $counts = array_count_values($values);
        ksort($counts);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    private static function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private static function later(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    private static function earlier(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}
