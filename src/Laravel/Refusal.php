<?php

namespace Shirahcan\CalendarClient\Laravel;

use RuntimeException;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceException;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\Exceptions\HoldExpired;
use Shirahcan\CalendarClient\Exceptions\SlotUnavailable;

/**
 * calendar-service refused (or could not be reached for) a time change. The message is safe to
 * show the person who asked; the cause is kept for logs and reports. Shared by every product
 * (K3): a product never writes its own copy of this.
 */
class Refusal extends RuntimeException
{
    public function __construct(string $message, public readonly CalendarServiceException $cause)
    {
        parent::__construct($message, 0, $cause);
    }

    /** The slot was taken, or a hold ran out: the person should simply pick again. */
    public function isRace(): bool
    {
        return $this->cause instanceof SlotUnavailable || $this->cause instanceof HoldExpired;
    }

    /** 409 taken (pick another), 503 unreachable (try shortly), 422 rejected. Never a 500. */
    public function status(): int
    {
        return match (true) {
            $this->isRace() => 409,
            $this->cause instanceof CalendarServiceUnavailable => 503,
            default => 422,
        };
    }

    /** The service's stable error code (`slot_unavailable`, `invalid_state`, ...), when it sent one. */
    public function code(): ?string
    {
        return $this->cause->errorCode;
    }
}
