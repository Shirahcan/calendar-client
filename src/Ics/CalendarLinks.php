<?php

namespace Shirahcan\CalendarClient\Ics;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * "Add to calendar" links for one event: Google's and Outlook's web forms, prefilled. Every
 * product's confirmation email and booking page uses these (Apple has no web form; a product links
 * its own .ics download, built with Ics, for it).
 *
 * The instants are exact whatever zone the caller's DateTimes carry. Google can also be told which
 * zone to SHOW the event in (`ctz`), so the calendar's clock matches the email beside it; without
 * one it shows the instant in the account's own zone.
 */
final class CalendarLinks
{
    public const GOOGLE = 'https://calendar.google.com/calendar/render';

    public const OUTLOOK = 'https://outlook.live.com/calendar/0/deeplink/compose';

    /** @param  ?string  $displayZone  IANA zone to show the event in; an unknown one is ignored */
    public static function google(
        string $title,
        DateTimeInterface $start,
        DateTimeInterface $end,
        string $details = '',
        string $location = '',
        ?string $displayZone = null,
    ): string {
        $zone = self::zone($displayZone);
        if ($zone !== null) {
            // With `ctz` the dates are read as local to that zone, so they carry no trailing Z.
            $dates = self::in($start, $zone)->format('Ymd\THis').'/'.self::in($end, $zone)->format('Ymd\THis');
        } else {
            $utc = new DateTimeZone('UTC');
            $dates = self::in($start, $utc)->format('Ymd\THis\Z').'/'.self::in($end, $utc)->format('Ymd\THis\Z');
        }

        return self::GOOGLE.'?'.self::query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $dates,
            'details' => $details,
            'location' => $location,
            'ctz' => $zone?->getName() ?? '',
        ]);
    }

    public static function outlook(
        string $title,
        DateTimeInterface $start,
        DateTimeInterface $end,
        string $details = '',
        string $location = '',
    ): string {
        $utc = new DateTimeZone('UTC');

        return self::OUTLOOK.'?'.self::query([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $title,
            'body' => $details,
            'location' => $location,
            'startdt' => self::in($start, $utc)->format('Y-m-d\TH:i:s\Z'),
            'enddt' => self::in($end, $utc)->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    private static function zone(?string $name): ?DateTimeZone
    {
        if ($name === null || $name === '' || ! in_array($name, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return new DateTimeZone($name);
    }

    private static function in(DateTimeInterface $at, DateTimeZone $zone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone($zone);
    }

    /** Empty values are left out, so a link never carries `location=` with nothing in it. */
    private static function query(array $params): string
    {
        return http_build_query(array_filter($params, fn ($v) => $v !== ''));
    }
}
