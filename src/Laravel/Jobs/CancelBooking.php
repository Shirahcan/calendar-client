<?php

namespace Shirahcan\CalendarClient\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Shirahcan\CalendarClient\Laravel\Seam;

/**
 * A cancellation the service could not take when the product ended a booking (K6). Retried until
 * it lands, because until then the time stays busy in every product.
 */
class CancelBooking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public function __construct(public readonly string $calendarBookingId) {}

    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(): void
    {
        (new Seam())->cancel($this->calendarBookingId, 'product', 'kit:retry');
    }
}
