<?php

namespace Shirahcan\CalendarClient;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Times in emails and calendar files, the same in every product: on the RECIPIENT's profile
 * clock, always naming the zone. (Screens use the viewer's device clock; that is calendar-ui's
 * useDisplayTimezone.) Estate rule, 2026-10-04.
 *
 * A bare "7:25 PM" with no zone is how one party read another party's clock as their own.
 */
final class ZonedTime
{
    /** "8:25 PM WAT" */
    public static function time(DateTimeInterface $instant, string $zone): string
    {
        return self::in($instant, $zone)->format('g:i A T');
    }

    /** "Wednesday, October 7, 2026" on the zone's calendar. */
    public static function date(DateTimeInterface $instant, string $zone): string
    {
        return self::in($instant, $zone)->format('l, F j, Y');
    }

    /** "Wednesday, October 7, 2026 at 8:25 PM WAT" */
    public static function dateTime(DateTimeInterface $instant, string $zone): string
    {
        return self::date($instant, $zone).' at '.self::time($instant, $zone);
    }

    /** "8:25 PM - 8:55 PM WAT": the zone once, at the end. */
    public static function timeRange(DateTimeInterface $start, DateTimeInterface $end, string $zone): string
    {
        return self::in($start, $zone)->format('g:i A').' - '.self::time($end, $zone);
    }

    private static function in(DateTimeInterface $instant, string $zone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone($zone));
    }
}
