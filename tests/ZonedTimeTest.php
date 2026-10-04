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

        $this->assertSame('8:25 PM West Africa Standard Time', ZonedTime::time($booked, 'Africa/Lagos'));
        $this->assertSame('Philippine Standard Time', ZonedTime::label($booked, 'Asia/Manila'));
        $this->assertSame('GMT', ZonedTime::label($booked, 'Africa/Accra'));
        $this->assertSame('Monday, September 28, 2026 at 3:25 PM EDT', ZonedTime::dateTime($booked, 'America/Toronto'));
        $this->assertSame('8:25 PM - 8:55 PM West Africa Standard Time', ZonedTime::timeRange($booked, $booked->modify('+30 minutes'), 'Africa/Lagos'));
        $this->assertSame('Tuesday, September 29, 2026', ZonedTime::date($booked, 'Asia/Manila'));
    }
}
