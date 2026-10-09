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
    /** "3:25 PM EDT", "8:25 PM WAT" */
    public static function time(DateTimeInterface $instant, string $zone): string
    {
        return self::in($instant, $zone)->format('g:i A').' '.self::label($instant, $zone);
    }

    /**
     * The zone's SHORT NAME at that instant (owner 2026-10-09: "3:00 PM WAT"): ICU's English
     * abbreviation where there is one ("EDT", "PST", "GMT"), else the curated one
     * (ZoneAbbreviations: "WAT", "IST", "CEST"), else the offset for a zone nobody has named.
     * The same rule and table as calendar-ui's zoneLabel, so screens and emails agree.
     */
    public static function label(DateTimeInterface $instant, string $zone): string
    {
        $short = class_exists(\IntlDateFormatter::class)
            ? self::icu($instant, $zone, 'zzz')
            : self::in($instant, $zone)->format('T');
        if (! preg_match('/^(GMT|UTC)?[+-]/', $short)) {
            return $short;
        }
        $known = ZoneAbbreviations::TABLE[$zone] ?? null;
        if ($known === null) {
            return $short;
        }

        return isset($known[1]) && self::onDaylightTime($instant, $zone) ? $known[1] : $known[0];
    }

    /** The zone's full name ("West Africa Standard Time"), for a sentence that names it. */
    public static function longName(DateTimeInterface $instant, string $zone): string
    {
        return class_exists(\IntlDateFormatter::class) ? self::icu($instant, $zone, 'zzzz') : self::in($instant, $zone)->format('T');
    }

    private static function icu(DateTimeInterface $instant, string $zone, string $pattern): string
    {
        return (string) (new \IntlDateFormatter('en_US', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, $pattern))
            ->format(DateTimeImmutable::createFromInterface($instant));
    }

    /** On summer time: its offset is above the zone's standard (the lower of January and July). */
    private static function onDaylightTime(DateTimeInterface $instant, string $zone): bool
    {
        $tz = new DateTimeZone($zone);
        $year = (int) self::in($instant, 'UTC')->format('Y');
        $standard = min(
            $tz->getOffset(new DateTimeImmutable("{$year}-01-01 00:00:00", new DateTimeZone('UTC'))),
            $tz->getOffset(new DateTimeImmutable("{$year}-07-01 00:00:00", new DateTimeZone('UTC'))),
        );

        return $tz->getOffset(DateTimeImmutable::createFromInterface($instant)) > $standard;
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
