<?php

namespace Shirahcan\CalendarClient;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A calendar day as the VIEWER lives it. The one place every product turns "the client picked
 * Sep 29" into instants, so a slot listed under a date is a slot ON that date for the person
 * reading it.
 *
 * The defect this exists to stop: a day was taken in the HOST's zone (or UTC) and its slots were
 * listed under the date the viewer clicked. A Lagos client clicked Sep 29 and booked a time that
 * was really Sep 28; a Manila client sees a Toronto consultant's late hours, which are the next
 * morning in Manila, under the wrong date. Slots are instants; only a day is zoned, and it is
 * always the viewer's.
 */
final class ViewerDay
{
    /**
     * [start, end) of $date (Y-m-d) in $zone, as UTC instants. A DST day is 23 or 25 hours long.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function window(string $date, string $zone): array
    {
        $tz = new DateTimeZone($zone);
        $start = self::midnight($date, $tz);
        $end = self::midnight($start->modify('+1 day')->format('Y-m-d'), $tz);
        $utc = new DateTimeZone('UTC');

        return [$start->setTimezone($utc), $end->setTimezone($utc)];
    }

    /** True when $instant falls on $date (Y-m-d) on the viewer's clock. */
    public static function onDate(DateTimeInterface $instant, string $date, string $zone): bool
    {
        return self::dateOf($instant, $zone) === $date;
    }

    /** The viewer-local date (Y-m-d) of $instant. */
    public static function dateOf(DateTimeInterface $instant, string $zone): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
    }

    /**
     * The host-local dates the viewer's $date overlaps: one when the zones agree on the day,
     * two when the viewer's day straddles the host's midnight.
     *
     * @return list<string>
     */
    public static function hostDates(string $date, string $viewerZone, string $hostZone): array
    {
        [$start, $end] = self::window($date, $viewerZone);
        $first = self::dateOf($start, $hostZone);
        $last = self::dateOf($end->modify('-1 second'), $hostZone);

        $dates = [$first];
        $cursor = $first;
        while ($cursor < $last) {
            $cursor = (new DateTimeImmutable($cursor.' 12:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            $dates[] = $cursor;
        }

        return $dates;
    }

    /**
     * Bookable slots per viewer-local date.
     *
     * @param iterable<Slot|DateTimeInterface> $slotsOrStarts
     * @return array<string, int> Y-m-d => count
     */
    public static function countByDate(iterable $slotsOrStarts, string $zone): array
    {
        $counts = [];
        foreach ($slotsOrStarts as $item) {
            $day = self::dateOf($item instanceof Slot ? $item->start : $item, $zone);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Local midnight of $date. Where a DST jump skips midnight (e.g. America/Santiago), PHP moves
     * the wall time forward to the first instant that exists, which is still on $date.
     */
    private static function midnight(string $date, DateTimeZone $tz): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' 00:00:00', $tz);
    }
}
