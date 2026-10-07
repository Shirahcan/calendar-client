<?php

namespace Shirahcan\CalendarClient\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\Ics\Ics;
use Shirahcan\CalendarClient\Ics\IcsEvent;
use Shirahcan\CalendarClient\Ics\IcsPerson;

class IcsTest extends TestCase
{
    private function event(array $over = []): IcsEvent
    {
        return new IcsEvent(...array_merge([
            'uid' => 'meeting-1@portify.shirah.co',
            'start' => new DateTimeImmutable('2026-11-02 10:00', new \DateTimeZone('America/Toronto')),
            'end' => new DateTimeImmutable('2026-11-02 10:30', new \DateTimeZone('America/Toronto')),
            'summary' => 'Spousal sponsorship, review; part 1',
            'sequence' => 3,
            'stamp' => new DateTimeImmutable('2026-10-07 12:00:00Z'),
            'description' => "When: Monday at 10:00 AM EST\nJoin from Portify.",
            'url' => 'https://portify.shirah.co/calendar/1',
            'organizer' => new IcsPerson('consultant@x.test', 'Ada Consultant'),
            'attendees' => [new IcsPerson('client@x.test', 'Maria Garcia'), new IcsPerson('spouse@x.test', 'José García', false), new IcsPerson('CLIENT@x.test', 'dup'), new IcsPerson('', 'no email')],
        ], $over));
    }

    public function test_times_are_exact_utc_and_the_text_is_escaped(): void
    {
        $ics = Ics::build('REQUEST', [$this->event()]);

        // 10:00 EST on 2 Nov 2026 (after the switch back) is 15:00 UTC.
        $this->assertStringContainsString("DTSTART:20261102T150000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20261102T153000Z\r\n", $ics);
        $this->assertStringNotContainsString('VTIMEZONE', $ics);
        $this->assertStringContainsString('SUMMARY:Spousal sponsorship\, review\; part 1', $ics);
        $this->assertStringContainsString('SEQUENCE:3', $ics);
        $this->assertStringContainsString('METHOD:REQUEST', $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function test_everyone_with_an_email_is_invited_once(): void
    {
        $ics = str_replace("\r\n ", '', Ics::build('REQUEST', [$this->event()]));

        $this->assertStringContainsString('ORGANIZER;CN="Ada Consultant":mailto:consultant@x.test', $ics);
        $this->assertSame(1, substr_count($ics, 'mailto:client@x.test') + substr_count($ics, 'mailto:CLIENT@x.test'));
        $this->assertStringContainsString('ATTENDEE;ROLE=OPT-PARTICIPANT;CN="José García":mailto:spouse@x.test', $ics);
        $this->assertStringNotContainsString('no email', $ics);
    }

    public function test_a_cancellation_is_cancelled_and_carries_no_alarm(): void
    {
        $ics = Ics::build('CANCEL', [$this->event()]);

        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
        $this->assertStringNotContainsString('VALARM', $ics);
    }

    public function test_long_lines_fold_at_75_octets_without_splitting_a_character(): void
    {
        $ics = Ics::build('REQUEST', [$this->event(['summary' => str_repeat('é', 80)])]);

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $this->assertStringContainsString('SUMMARY:'.str_repeat('é', 80), str_replace("\r\n ", '', $ics));
    }

    public function test_the_filename_reads_the_date_on_the_given_clock(): void
    {
        $late = $this->event(['start' => new DateTimeImmutable('2026-11-03 02:00Z'), 'summary' => 'Late call']);

        $this->assertSame('meeting-2026-11-02-late-call.ics', Ics::filename($late, 'America/Toronto'));
        $this->assertSame('meeting-2026-11-03-late-call.ics', Ics::filename($late));
    }
}
