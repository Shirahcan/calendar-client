<?php

namespace Shirahcan\CalendarClient\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Shirahcan\CalendarClient\Laravel\Bookings\BookingSubject;
use Shirahcan\CalendarClient\Laravel\Seam;

/**
 * The service booking was made BEFORE the product's insert (AuthorityObserver::creating). If that
 * insert failed or its transaction rolled back, the time would stay busy in every product for a
 * booking nobody has. This gives it back (K6).
 */
class VerifyLanded implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DELAY_SECONDS = 120;

    public int $tries = 5;

    /** @param class-string<BookingSubject> $subject */
    public function __construct(public readonly string $subject, public readonly string $ref, public readonly string $calendarBookingId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        /** @var BookingSubject $s */
        $s = app($this->subject);
        if ($s->linkedByRef($this->ref) !== $this->calendarBookingId) {
            (new Seam())->cancel($this->calendarBookingId, 'system', 'kit:verify', 'The record this time was booked for was never saved.');
        }
    }
}
