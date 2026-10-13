<?php

namespace Shirahcan\CalendarClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\Booking;

/** The kit reads the service's outcome, and derives it the service's way when absent. */
class BookingOutcomeTest extends TestCase
{
    private function row(string $state, array $people = [], array $hostAttendance = [], string $end = '2026-10-12T14:00:00Z'): array
    {
        return ['id' => 'b1', 'state' => $state, 'start_utc' => '2026-10-12T13:00:00Z', 'end_utc' => $end,
            'hosts' => ['h1'], 'participants' => $people, 'host_attendance' => $hostAttendance];
    }

    public function test_the_services_word_wins(): void
    {
        $b = Booking::fromArray(['outcome' => 'no_show'] + $this->row('completed'));

        $this->assertSame('no_show', $b->outcome());
    }

    public function test_completed_with_nobody_recorded_is_never_held(): void
    {
        $now = strtotime('2026-10-12T15:00:00Z');

        $this->assertSame('awaiting_outcome', Booking::deriveOutcome($this->row('completed'), $now));
        $this->assertSame('awaiting_outcome', Booking::deriveOutcome($this->row('confirmed'), $now));
        $this->assertSame('upcoming', Booking::deriveOutcome($this->row('confirmed'), strtotime('2026-10-12T13:30:00Z')));
        $this->assertSame('unrecorded', Booking::deriveOutcome($this->row('completed'), strtotime('2026-10-16T00:00:00Z')));
    }

    public function test_attendance_names_who_missed_it(): void
    {
        $now = strtotime('2026-10-12T15:00:00Z');
        $came = [['role' => 'booker', 'attendance' => 'attended']];
        $missed = [['role' => 'booker', 'attendance' => 'no_show']];

        $this->assertSame('held', Booking::deriveOutcome($this->row('completed', $came, ['h1' => 'attended']), $now));
        $this->assertSame('no_show', Booking::deriveOutcome($this->row('completed', $missed, ['h1' => 'attended']), $now));
        $this->assertSame('host_no_show', Booking::deriveOutcome($this->row('completed', $came, ['h1' => 'no_show']), $now));
        $this->assertSame('nobody_came', Booking::deriveOutcome($this->row('completed', $missed, ['h1' => 'no_show']), $now));
        $this->assertSame('cancelled', Booking::deriveOutcome($this->row('cancelled'), $now));
    }
}
