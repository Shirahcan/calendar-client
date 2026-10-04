<?php

namespace Shirahcan\CalendarClient\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\BookingDays;

class BookingDaysTest extends TestCase
{
    public function test_host_day_groups_are_regrouped_under_the_bookers_days(): void
    {
        // A Calgary host's Oct 5, 09:00 and 16:00 (15:00Z and 22:00Z): in Manila Oct 5 23:00 and Oct 6 06:00.
        $hostDays = [['date' => '2026-10-05', 'day_label' => 'Monday, October 5', 'slots' => [
            ['start' => '2026-10-05T22:00:00+00:00'],
            ['start' => '2026-10-05T15:00:00+00:00'],
        ]]];

        $groups = BookingDays::regroup($hostDays, 'Asia/Manila', '2026-10-05', '2026-10-06');

        $this->assertSame(['2026-10-05', '2026-10-06'], array_column($groups, 'date'));
        $this->assertSame('Tuesday, October 6', $groups[1]['day_label']);
        $this->assertSame('2026-10-05T15:00:00+00:00', $groups[0]['slots'][0]['start']);

        // Outside the booker's window is dropped.
        $this->assertSame(['2026-10-06'], array_column(BookingDays::regroup($hostDays, 'Asia/Manila', '2026-10-06', '2026-10-06'), 'date'));
    }

    public function test_the_host_window_covers_every_booker_day(): void
    {
        $this->assertSame(['2026-09-30', '2026-11-01'], BookingDays::hostWindow('2026-10-01', '2026-10-31'));
        $this->assertSame('2026-10-07', BookingDays::firstDate('2026-10-01', 'Asia/Manila', new DateTimeImmutable('2026-10-06T20:00:00Z')));
    }

    public function test_the_heatmap_counts_the_bookers_days(): void
    {
        $map = BookingDays::heatmap([['date' => '2026-10-06', 'slots' => array_fill(0, 3, [])]], '2026-10');

        $this->assertCount(31, $map['days']);
        $this->assertSame(['date' => '2026-10-06', 'day_of_week' => 'Tuesday', 'slot_count' => 3, 'availability_level' => 'medium', 'color' => 'yellow', 'bookable' => true], $map['days'][5]);
        $this->assertSame(30, $map['metadata']['unavailable_days']);
    }

    public function test_a_slot_off_the_date_the_booker_saw_is_refused(): void
    {
        // The reported booking: 19:25Z on Sep 28 is Monday Sep 28 in Lagos, not the 29th.
        $this->assertSame(
            'That time is on Monday, September 28 in your timezone, not the day you picked. Please choose your time again.',
            BookingDays::dateMismatch('2026-09-28T19:25:00Z', '2026-09-29', 'Africa/Lagos'),
        );
        $this->assertNull(BookingDays::dateMismatch('2026-09-28T19:25:00Z', '2026-09-28', 'Africa/Lagos'));
        $this->assertNull(BookingDays::dateMismatch('2026-09-28T19:25:00Z', null, 'Africa/Lagos'));
    }
}
