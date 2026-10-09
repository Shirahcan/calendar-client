<?php

namespace Shirahcan\CalendarClient\Laravel\Bookings;

use Shirahcan\CalendarClient\Booking;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceException;

/**
 * The bookings this request has read, so a record loaded twice, or a list and then one of its
 * rows, costs one call (request-scoped). Never a copy that outlives the request: the service is
 * asked again next time.
 *
 * A service that cannot answer is an error (reported once per request), never a stale fallback.
 */
final class HeldBookings
{
    /** @var array<string, Booking|null> booking id => booking (null = the service has none) */
    private array $bookings = [];

    private bool $reported = false;

    /**
     * Fetch every id not already known, in one call.
     *
     * @param list<string> $ids
     * @return array<string, Booking|null>
     */
    public function load(array $ids): array
    {
        $missing = array_values(array_unique(array_filter($ids, fn ($id) => $id !== '' && ! array_key_exists($id, $this->bookings))));
        if ($missing !== []) {
            try {
                $found = app(CalendarClient::class)->bookings($missing);
            } catch (CalendarServiceException $e) {
                if (! $this->reported) {
                    $this->reported = true;
                    report($e);
                }

                throw $e;
            }
            foreach ($missing as $id) {
                $this->bookings[$id] = $found[$id] ?? null;
            }
        }

        return array_intersect_key($this->bookings, array_flip($ids));
    }

    public function get(string $id): ?Booking
    {
        return $this->load([$id])[$id] ?? null;
    }

    /** A booking the service just returned (a write's answer, a search row). */
    public function put(Booking $booking): void
    {
        $this->bookings[$booking->id] = $booking;
    }

    /** After a change whose answer was not kept: the next read asks again. */
    public function forget(?string $id): void
    {
        if ($id !== null) {
            unset($this->bookings[$id]);
        }
    }
}
