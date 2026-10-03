<?php

namespace Shirahcan\CalendarClient\Laravel;

use DateTimeInterface;
use Shirahcan\CalendarClient\Booking;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\Exceptions\CalendarNotFound;
use Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceException;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\Exceptions\HoldExpired;
use Shirahcan\CalendarClient\Exceptions\SlotUnavailable;

/**
 * A product's ONE seam to calendar-service for booking time (K3). Every call either returns the
 * service's answer or throws a Refusal whose message is safe to show the person who asked.
 *
 * ⚠ Fails CLOSED: an unreachable service is a refusal ("try again in a moment"), never a quiet
 * fallback to the product's own engine. Server faults are reported; a taken slot or an expired
 * hold is the expected outcome of a race and is not.
 *
 * Ending time is idempotent: cancelling something already cancelled, or releasing a hold that has
 * already expired, is the outcome asked for, not an error.
 *
 * Products extend this only to add their own MEANING (which of their records maps to which
 * reference, who it keeps busy); the lifecycle and the wording live here, once.
 */
class Seam
{
    public const TAKEN = 'That time is no longer available. Please pick another.';
    public const HOLD_EXPIRED = 'Your hold on that time ran out. Please pick the time again.';
    public const UNAVAILABLE = 'Scheduling is briefly unavailable. Please try again in a moment.';
    public const FAILED = 'Scheduling could not complete that request. Please try again.';

    protected function client(): CalendarClient
    {
        return app(CalendarClient::class);
    }

    public function hold(string $bookingTypeRef, DateTimeInterface $start, string $idempotencyKey, array $details = [], ?int $durationMinutes = null): Booking
    {
        $details = $durationMinutes === null ? $details : $details + ['duration' => $durationMinutes];

        return $this->call(fn () => $this->client()->hold($bookingTypeRef, $start, $idempotencyKey, $details));
    }

    public function confirm(string $bookingId, ?string $actor = null): Booking
    {
        return $this->call(fn () => $this->client()->confirm($bookingId, $actor));
    }

    public function extend(string $bookingId, int $seconds): Booking
    {
        return $this->call(fn () => $this->client()->extendHold($bookingId, $seconds));
    }

    /** Give a held slot back. Never throws: an unreturned hold expires on its own TTL. */
    public function release(string $bookingId, ?string $actor = null): void
    {
        try {
            $this->client()->release($bookingId, $actor);
        } catch (CalendarNotFound|CalendarRequestRejected) {
            // Already released, expired or confirmed: nothing held to give back.
        } catch (CalendarServiceException $e) {
            report($e);
        }
    }

    /** @param 'host'|'booker'|'product'|'system' $by */
    public function cancel(string $bookingId, string $by = 'product', ?string $actor = null, ?string $reason = null): void
    {
        try {
            $this->client()->cancel($bookingId, $by, $actor, $reason);
        } catch (CalendarNotFound) {
            // Nothing to cancel there.
        } catch (CalendarRequestRejected $e) {
            if ($e->errorCode !== 'invalid_state') {
                throw $this->refusal($e);
            }
            // Already cancelled, declined or completed: the outcome asked for.
        } catch (CalendarServiceException $e) {
            throw $this->refusal($e);
        }
    }

    /** A move made by the product or a host: their free time is enough (`$hostOverride`). */
    public function reschedule(string $bookingId, DateTimeInterface $start, DateTimeInterface $end, bool $hostOverride = true, ?string $actor = null): Booking
    {
        return $this->call(fn () => $this->client()->reschedule($bookingId, $start, $actor, null, $end, $hostOverride));
    }

    public function approve(string $bookingId, ?string $actor = null): Booking
    {
        return $this->call(fn () => $this->client()->approve($bookingId, $actor));
    }

    public function decline(string $bookingId, ?string $actor = null, ?string $reason = null): Booking
    {
        return $this->call(fn () => $this->client()->decline($bookingId, $actor, $reason));
    }

    /**
     * A booking the product writes outside a hold (a host's own meeting, an admin's call):
     * busy-only check on everyone it keeps busy, confirmed at once. `$ref` is the product's
     * reference AND the idempotency key, so it must exist BEFORE the product's own insert
     * (a uuid, never an auto-increment id).
     */
    public function createMeeting(array $hostAuthIds, DateTimeInterface $start, DateTimeInterface $end, string $ref, array $details = [], ?string $idempotencyKey = null): Booking
    {
        // The product's reference is the idempotency key unless the product books the same record
        // a second time (reopened after it ended): then a fresh key, the same reference.
        return $this->call(fn () => $this->client()->createMeeting($hostAuthIds, $start, $end, $idempotencyKey ?? $ref, ['product_ref' => $ref] + $details));
    }

    /** People joining or leaving a booked call; `$checkBusy` refuses someone busy then. */
    public function setHosts(string $bookingId, array $hostAuthIds, bool $checkBusy = true, ?string $actor = null): Booking
    {
        return $this->call(fn () => $this->client()->setHosts($bookingId, $hostAuthIds, $checkBusy, $actor));
    }

    /**
     * The calendar copy's text, and whose calendars receive it. Text written for ONE person (their
     * own join link) must go to that person only: pass `[$thatPerson]` as `$writeTo`.
     *
     * @param list<string>|null|false $writeTo false = leave who receives it unchanged
     */
    public function describe(string $bookingId, ?string $title, ?string $description, ?string $location, array|null|false $writeTo = false): Booking
    {
        $booking = $this->call(fn () => $this->client()->updateDetails(
            $bookingId,
            $title === null ? null : mb_substr($title, 0, 200),
            $description,
            $location === null ? null : (mb_substr($location, 0, 500) ?: null),
        ));
        if ($writeTo === false) {
            return $booking;
        }

        // Only people who ARE hosts of it can receive a copy.
        $writeTo = $writeTo === null ? null : array_values(array_intersect($writeTo, $booking->hosts));

        return $this->call(fn () => $this->client()->writeTo($bookingId, $writeTo));
    }

    /**
     * Make a booking the product made before its cutover one the service owns: promote its mirror
     * (adopting the calendar events the product already wrote), or null when there is no mirror.
     *
     * @param list<array{provider: string, account_email: string, event_id: string, calendar_id?: ?string}> $externalEvents
     */
    public function promote(string $mirrorRef, array $externalEvents = []): ?Booking
    {
        try {
            return $this->client()->promoteMirror($mirrorRef, $externalEvents);
        } catch (CalendarNotFound) {
            return null;
        } catch (CalendarServiceException $e) {
            throw $this->refusal($e);
        }
    }

    /** @template T @param callable(): T $fn @return T */
    public function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (CalendarServiceException $e) {
            throw $this->refusal($e);
        }
    }

    public function refusal(CalendarServiceException $e): Refusal
    {
        $message = match (true) {
            $e instanceof SlotUnavailable => self::TAKEN,
            $e instanceof HoldExpired => self::HOLD_EXPIRED,
            $e instanceof CalendarServiceUnavailable => self::UNAVAILABLE,
            $e instanceof CalendarRequestRejected => $e->getMessage(),
            default => self::FAILED,
        };

        if (! $e instanceof SlotUnavailable && ! $e instanceof HoldExpired) {
            report($e);
        }

        return new Refusal($message, $e);
    }
}
