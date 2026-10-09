<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

/**
 * Who is using a meeting's notes or scratchpad, as the PRODUCT decided (ScratchpadAccess).
 * `bookingId` is the meeting's calendar-service booking: notes and pads live there (plan N1).
 * `context` carries whatever the product's targets and actions need (Portify: the case id), so
 * the kit never has to know what a case is.
 */
final class ScratchpadViewer
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $meetingId,
        public readonly string $userId,
        public readonly array $context = [],
        public readonly ?string $bookingId = null,
    ) {}

    /** The booking the notes live on; a meeting the service does not hold has none to offer. */
    public function booking(): string
    {
        return $this->bookingId ?? throw new \RuntimeException("Meeting {$this->meetingId} is not held in calendar-service, so it has no notes there.");
    }
}
