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
    /** "3:25 PM EDT", "8:25 PM West Africa Standard Time" */
    public static function time(DateTimeInterface $instant, string $zone): string
    {
        return self::in($instant, $zone)->format('g:i A').' '.self::label($instant, $zone);
    }

    /**
     * The zone's NAME at that instant: its abbreviation where ICU has one ("EDT", "MDT", "GMT"),
     * else its full name ("West Africa Standard Time"). Never a bare offset ("GMT+1", "+04"):
     * it names no place. The same rule as calendar-ui's zoneLabel, so screens and emails agree.
     */
    public static function label(DateTimeInterface $instant, string $zone): string
    {
        if (! class_exists(\IntlDateFormatter::class)) {
            return self::in($instant, $zone)->format('T');
        }
        $name = fn (string $pattern): string => (string) (new \IntlDateFormatter('en_US', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, $pattern))
            ->format(DateTimeImmutable::createFromInterface($instant));
        $short = $name('zzz');

        return preg_match('/^(GMT|UTC)[+-]/', $short) ? $name('zzzz') : $short;
    }

    /** "Wednesday, October 7, 2026" on the zone's calendar. */
    public static function date(DateTimeInterface $instant, string $zone): string
    {
        return self::in($instant, $zone)->format('l, F j, Y');
    }

    /** "Wednesday, October 7, 2026 at 3:25 PM EDT" */
    public static function dateTime(DateTimeInterface $instant, string $zone): string
    {
        return self::date($instant, $zone).' at '.self::time($instant, $zone);
    }

    /** "8:25 PM - 8:55 PM West Africa Standard Time": the zone once, at the end. */
    public static function timeRange(DateTimeInterface $start, DateTimeInterface $end, string $zone): string
    {
        return self::in($start, $zone)->format('g:i A').' - '.self::time($end, $zone);
    }

    private static function in(DateTimeInterface $instant, string $zone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone($zone));
    }
}
