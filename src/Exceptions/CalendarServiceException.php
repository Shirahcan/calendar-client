<?php

namespace Shirahcan\CalendarClient\Exceptions;

use RuntimeException;

/**
 * Base for every calendar-service failure. `$errorCode` is the service's stable machine
 * code (slot_unavailable, hold_expired, invalid_state, ...); `$errors` carries every
 * validation message when the service returned a list.
 */
class CalendarServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $status = 0,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
