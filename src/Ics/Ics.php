<?php

namespace Shirahcan\CalendarClient\Ics;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Calendar invites (.ics, RFC 5545), the same in every product.
 *
 * ⚠ TIMES ARE WRITTEN IN UTC (`DTSTART:...Z`). That is exact for every reader: Google, Outlook and
 * Apple show a UTC event on the reader's own calendar clock. A hand-built VTIMEZONE is how invites
 * go wrong (Portify's wrote the same offset as TZOFFSETFROM and TZOFFSETTO and had no RRULE, so a
 * client could shift the meeting across a daylight-saving change). The zone a person thinks in is
 * NAMED in the description instead (ZonedTime), where it cannot be misread.
 */
final class Ics
{
    private const MAX_OCTETS = 75;

    /**
     * @param  'REQUEST'|'CANCEL'|'PUBLISH'  $method
     * @param  list<IcsEvent>  $events
     */
    public static function build(string $method, array $events, string $productId = '-//Shirah//Calendar//EN'): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'.$productId, 'CALSCALE:GREGORIAN', 'METHOD:'.$method];
        foreach ($events as $event) {
            array_push($lines, ...self::event($event, $method));
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** "meeting-2026-10-12-spousal-sponsorship-review.ics" */
    public static function filename(IcsEvent $event, ?string $zone = null): string
    {
        $date = DateTimeImmutable::createFromInterface($event->start)->setTimezone(new DateTimeZone($zone ?? 'UTC'))->format('Y-m-d');
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($event->summary)), '-');

        return 'meeting-'.$date.($slug !== '' ? '-'.substr($slug, 0, 30) : '').'.ics';
    }

    /** RFC 5545 TEXT: backslash, semicolon and comma escaped, newlines as \n. */
    public static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** @return list<string> */
    private static function event(IcsEvent $e, string $method): array
    {
        $status = $method === 'CANCEL' ? 'CANCELLED' : $e->status;
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$e->uid,
            'DTSTAMP:'.self::utc($e->stamp),
            'DTSTART:'.self::utc($e->start),
            'DTEND:'.self::utc($e->end),
            'SUMMARY:'.self::escape($e->summary),
        ];
        if ($e->description !== null && $e->description !== '') {
            $lines[] = 'DESCRIPTION:'.self::escape($e->description);
        }
        if ($e->url !== null && $e->url !== '') {
            $lines[] = 'LOCATION:'.self::escape($e->url);
            $lines[] = 'URL:'.$e->url;
        }
        if ($e->organizer !== null && $e->organizer->email !== '') {
            $lines[] = 'ORGANIZER'.self::cn($e->organizer->name).':mailto:'.$e->organizer->email;
        }
        $seen = [];
        foreach ($e->attendees as $a) {
            $key = strtolower($a->email);
            if ($a->email === '' || isset($seen[$key]) || ($e->organizer && strtolower($e->organizer->email) === $key)) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = 'ATTENDEE;ROLE='.($a->required ? 'REQ-PARTICIPANT' : 'OPT-PARTICIPANT').self::cn($a->name).':mailto:'.$a->email;
        }
        array_push($lines, 'STATUS:'.$status, 'SEQUENCE:'.max(0, $e->sequence), 'TRANSP:OPAQUE', 'CLASS:PUBLIC');
        if ($e->alarmMinutesBefore !== null && $e->alarmMinutesBefore > 0 && $status !== 'CANCELLED') {
            array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Meeting reminder', 'TRIGGER:-PT'.$e->alarmMinutesBefore.'M', 'END:VALARM');
        }
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private static function cn(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : ';CN="'.str_replace('"', "'", $name).'"';
    }

    private static function utc(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** Fold at 75 OCTETS, never inside a UTF-8 character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_OCTETS) {
            return $line;
        }
        $out = [];
        $current = '';
        $limit = self::MAX_OCTETS;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
                $limit = self::MAX_OCTETS - 1; // continuation lines start with a space
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
