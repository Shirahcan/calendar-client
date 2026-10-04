<?php

namespace Shirahcan\CalendarClient\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\Slot;
use Shirahcan\CalendarClient\ViewerDay;

class ViewerDayTest extends TestCase
{
    public function test_a_day_is_the_viewers_midnight_to_midnight(): void
    {
        [$from, $to] = ViewerDay::window('2026-09-29', 'Africa/Lagos');

        $this->assertSame('2026-09-28T23:00:00+00:00', $from->format(DATE_ATOM));
        $this->assertSame('2026-09-29T23:00:00+00:00', $to->format(DATE_ATOM));
    }

    public function test_a_dst_day_keeps_its_real_length(): void
    {
        [$from, $to] = ViewerDay::window('2026-11-01', 'America/Toronto');   // fall back: 25 hours

        $this->assertSame(25 * 3600, $to->getTimestamp() - $from->getTimestamp());
    }

    public function test_the_lagos_report_is_on_the_day_it_says(): void
    {
        // The booked instant: 19:25 UTC on Sep 28 is 8:25 PM on Sep 28 in Lagos, never Sep 29.
        $booked = new DateTimeImmutable('2026-09-28T19:25:00Z');

        $this->assertTrue(ViewerDay::onDate($booked, '2026-09-28', 'Africa/Lagos'));
        $this->assertFalse(ViewerDay::onDate($booked, '2026-09-29', 'Africa/Lagos'));
    }

    public function test_a_viewer_day_can_straddle_two_host_days(): void
    {
        // Manila's Oct 6 runs Oct 5 12:00 to Oct 6 12:00 in Toronto.
        $this->assertSame(['2026-10-05', '2026-10-06'], ViewerDay::hostDates('2026-10-06', 'Asia/Manila', 'America/Toronto'));
        $this->assertSame(['2026-10-05'], ViewerDay::hostDates('2026-10-05', 'America/Toronto', 'America/Toronto'));
    }

    public function test_counts_are_keyed_by_the_viewers_date(): void
    {
        // A Toronto consultant's 09:00 and 16:00 on Oct 5: Manila reads them as Oct 5 21:00 and Oct 6 04:00.
        $slots = [
            new Slot(new DateTimeImmutable('2026-10-05T13:00:00Z'), new DateTimeImmutable('2026-10-05T13:30:00Z'), ['h']),
            new Slot(new DateTimeImmutable('2026-10-05T20:00:00Z'), new DateTimeImmutable('2026-10-05T20:30:00Z'), ['h']),
        ];

        $this->assertSame(['2026-10-05' => 1, '2026-10-06' => 1], ViewerDay::countByDate($slots, 'Asia/Manila'));
        $this->assertSame(['2026-10-05' => 2], ViewerDay::countByDate($slots, 'America/Toronto'));
    }
}
