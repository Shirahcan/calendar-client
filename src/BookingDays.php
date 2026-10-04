<?php

namespace Shirahcan\CalendarClient;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The booking-page rules every product shares, so none of them keeps its own copy:
 *
 *  - a slot list is grouped under the BOOKER's days (never the host's, never UTC);
 *  - a month heatmap counts those same days, so a cell never promises a day the list shows empty;
 *  - a hold is refused when the slot is not on the date the booker saw it under.
 *
 * A product whose own engine walks the HOST's days (MployNow, Portify's legacy engine) computes
 * one host day either side of the window (hostWindow) and hands its groups to regroup().
 * Slots from calendar-service are instants already: group them with regroup() directly.
 */
final class BookingDays
{
    /**
     * The host days to compute so every booker day in [$first, $last] (Y-m-d) is complete: a
     * booker's day can begin on the host's previous day or end on the host's next one.
     *
     * @return array{0: string, 1: string} Y-m-d, Y-m-d
     */
    public static function hostWindow(string $first, string $last): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            (new DateTimeImmutable($first.' 12:00:00', $utc))->modify('-1 day')->format('Y-m-d'),
            (new DateTimeImmutable($last.' 12:00:00', $utc))->modify('+1 day')->format('Y-m-d'),
        ];
    }

    /** The first booker date to offer: $windowStart, never before the booker's today. */
    public static function firstDate(string $windowStart, string $zone, ?DateTimeInterface $now = null): string
    {
        return max($windowStart, ViewerDay::dateOf($now ?? new DateTimeImmutable('now'), $zone));
    }

    /**
     * Group slots under the booker's dates, keeping only dates in [$first, $last].
     *
     * Takes either a flat list of slots or host-day groups ([{slots: [...]}, ...]); each slot is an
     * array whose $startKey holds an ISO instant, or a Slot.
     *
     * @return list<array{date: string, day_label: string, slots: list<mixed>}>
     */
    public static function regroup(array $slotsOrGroups, string $zone, string $first, string $last, string $startKey = 'start'): array
    {
        $slots = [];
        foreach ($slotsOrGroups as $item) {
            if (is_array($item) && isset($item['slots']) && is_array($item['slots'])) {
                array_push($slots, ...$item['slots']);
            } else {
                $slots[] = $item;
            }
        }

        $byDate = [];
        foreach ($slots as $slot) {
            $start = $slot instanceof Slot ? $slot->start : new DateTimeImmutable((string) $slot[$startKey]);
            $date = ViewerDay::dateOf($start, $zone);
            if ($date < $first || $date > $last) {
                continue;
            }
            $byDate[$date][$start->getTimestamp()] = $slot;
        }
        ksort($byDate);

        $groups = [];
        foreach ($byDate as $date => $daySlots) {
            ksort($daySlots);
            $groups[] = ['date' => $date, 'day_label' => self::dayLabel($date), 'slots' => array_values($daySlots)];
        }

        return $groups;
    }

    /** "Wednesday, October 7" for a Y-m-d date (a calendar date: no zone to apply). */
    public static function dayLabel(string $date): string
    {
        return (new DateTimeImmutable($date.' 12:00:00', new DateTimeZone('UTC')))->format('l, F j');
    }

    /**
     * A month heatmap on the booker's calendar, from regroup()ed days.
     *
     * @param list<array{date: string, slots: list<mixed>}> $groups
     * @return array{month: string, days: list<array<string, mixed>>, metadata: array<string, int>}
     */
    public static function heatmap(array $groups, string $month): array
    {
        $counts = [];
        foreach ($groups as $group) {
            $counts[$group['date']] = count($group['slots']);
        }

        $metadata = ['total_slots' => 0, 'high_availability_days' => 0, 'medium_availability_days' => 0, 'low_availability_days' => 0, 'unavailable_days' => 0];
        $days = [];
        $utc = new DateTimeZone('UTC');
        $cursor = new DateTimeImmutable($month.'-01 12:00:00', $utc);
        $end = $cursor->modify('last day of this month');
        for (; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $count = $counts[$date] ?? 0;
            $level = self::level($count);
            $days[] = [
                'date' => $date,
                'day_of_week' => $cursor->format('l'),
                'slot_count' => $count,
                'availability_level' => $level,
                'color' => ['high' => 'green', 'medium' => 'yellow', 'low' => 'orange'][$level] ?? 'gray',
                'bookable' => $count > 0,
            ];
            $metadata['total_slots'] += $count;
            $metadata[$level === 'none' ? 'unavailable_days' : "{$level}_availability_days"]++;
        }

        return ['month' => $month, 'days' => $days, 'metadata' => $metadata];
    }

    /** high 8+, medium 3-7, low 1-2, none. */
    public static function level(int $count): string
    {
        return $count >= 8 ? 'high' : ($count >= 3 ? 'medium' : ($count >= 1 ? 'low' : 'none'));
    }

    /**
     * What the booker picked is what gets booked. A slot they saw under $localDate (their calendar,
     * $zone) must be ON it; returns the refusal to show them, or null when it is (or when no date
     * was sent, for older clients).
     */
    public static function dateMismatch(string $slotStartUtc, ?string $localDate, ?string $zone): ?string
    {
        if ($localDate === null || $localDate === '') {
            return null;
        }
        $zone = $zone ?: 'UTC';
        try {
            $start = new DateTimeImmutable($slotStartUtc);
            if (ViewerDay::onDate($start, $localDate, $zone)) {
                return null;
            }
            $actual = $start->setTimezone(new DateTimeZone($zone))->format('l, F j');
        } catch (\Throwable) {
            return null;   // an unreadable zone or instant is the validator's business, not this guard's
        }

        return "That time is on {$actual} in your timezone, not the day you picked. Please choose your time again.";
    }
}
