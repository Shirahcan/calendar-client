<?php

namespace Shirahcan\CalendarClient\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\ZonedTime;

class ZonedTimeTest extends TestCase
{
    public function test_every_time_names_its_zone_on_the_recipients_clock(): void
    {
        $booked = new DateTimeImmutable('2026-09-28T19:25:00Z');

        $this->assertSame('8:25 PM WAT', ZonedTime::time($booked, 'Africa/Lagos'));
        $this->assertSame('PHT', ZonedTime::label($booked, 'Asia/Manila'));
        $this->assertSame('West Africa Standard Time', ZonedTime::longName($booked, 'Africa/Lagos'));
        $this->assertSame('BST', ZonedTime::label(new \DateTimeImmutable('2026-07-01T12:00:00Z'), 'Europe/London'));
        $this->assertSame('GMT', ZonedTime::label(new \DateTimeImmutable('2026-12-01T12:00:00Z'), 'Europe/London'));
        $this->assertSame('CEST', ZonedTime::label(new \DateTimeImmutable('2026-07-01T12:00:00Z'), 'Europe/Paris'));
        $this->assertSame('AEDT', ZonedTime::label(new \DateTimeImmutable('2026-01-15T12:00:00Z'), 'Australia/Sydney'));
        $this->assertSame('GMT', ZonedTime::label($booked, 'Africa/Accra'));
        $this->assertSame('Monday, September 28, 2026 at 3:25 PM EDT', ZonedTime::dateTime($booked, 'America/Toronto'));
        $this->assertSame('8:25 PM - 8:55 PM WAT', ZonedTime::timeRange($booked, $booked->modify('+30 minutes'), 'Africa/Lagos'));
        $this->assertSame('Tuesday, September 29, 2026', ZonedTime::date($booked, 'Asia/Manila'));
    }
}
