<?php

namespace Shirahcan\CalendarClient\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\Ics\CalendarLinks;

class CalendarLinksTest extends TestCase
{
    private function start(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-02 10:00', new DateTimeZone('America/Toronto'));
    }

    private function end(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-02 10:30', new DateTimeZone('America/Toronto'));
    }

    /** @return array<string, string> */
    private function params(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $out);

        return $out;
    }

    public function test_google_carries_the_exact_instant_in_utc(): void
    {
        $url = CalendarLinks::google('Interview, part 1 & 2', $this->start(), $this->end(), 'Join from MployNow', 'https://x.test/room/1');

        $this->assertStringStartsWith(CalendarLinks::GOOGLE.'?', $url);
        $p = $this->params($url);
        $this->assertSame('TEMPLATE', $p['action']);
        $this->assertSame('Interview, part 1 & 2', $p['text']);
        $this->assertSame('20261102T150000Z/20261102T153000Z', $p['dates']);
        $this->assertSame('Join from MployNow', $p['details']);
        $this->assertSame('https://x.test/room/1', $p['location']);
        $this->assertArrayNotHasKey('ctz', $p);
    }

    public function test_google_shows_the_event_in_the_zone_it_is_given(): void
    {
        $p = $this->params(CalendarLinks::google('Call', $this->start(), $this->end(), displayZone: 'Africa/Lagos'));

        // Local to Lagos (UTC+1), with no Z: Google reads them in `ctz`.
        $this->assertSame('20261102T160000/20261102T163000', $p['dates']);
        $this->assertSame('Africa/Lagos', $p['ctz']);
    }

    public function test_an_unknown_zone_is_ignored(): void
    {
        $p = $this->params(CalendarLinks::google('Call', $this->start(), $this->end(), displayZone: 'Mars/Olympus'));

        $this->assertSame('20261102T150000Z/20261102T153000Z', $p['dates']);
        $this->assertArrayNotHasKey('ctz', $p);
    }

    public function test_outlook_opens_the_compose_form_in_utc(): void
    {
        $url = CalendarLinks::outlook('Call', $this->start(), $this->end(), 'Details', 'Room');

        $this->assertStringStartsWith(CalendarLinks::OUTLOOK.'?', $url);
        $p = $this->params($url);
        $this->assertSame('/calendar/action/compose', $p['path']);
        $this->assertSame('addevent', $p['rru']);
        $this->assertSame('Call', $p['subject']);
        $this->assertSame('2026-11-02T15:00:00Z', $p['startdt']);
        $this->assertSame('2026-11-02T15:30:00Z', $p['enddt']);
        $this->assertSame('Details', $p['body']);
        $this->assertSame('Room', $p['location']);
    }

    public function test_empty_fields_are_left_out(): void
    {
        $p = $this->params(CalendarLinks::outlook('Call', $this->start(), $this->end()));

        $this->assertArrayNotHasKey('body', $p);
        $this->assertArrayNotHasKey('location', $p);
    }
}
