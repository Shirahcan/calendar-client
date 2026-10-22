<?php

namespace Shirahcan\CalendarClient\Exceptions;

use RuntimeException;

/**
 * Base for every calendar-service failure. `$errorCode` is the service's stable machine
 * code (slot_unavailable, hold_expired, invalid_state, ...); `$errors` carries every
 * validation message when the service returned a list. `$alternative` is what the service offers
 * instead, when it offers one: `reschedule` for a booker refused a late cancel (notice_window).
 */
class CalendarServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $status = 0,
        public readonly array $errors = [],
        public readonly ?string $alternative = null,
    ) {
        parent::__construct($message);
    }
}
