<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

/**
 * Who is using a meeting's scratchpad, as the PRODUCT decided (ScratchpadAccess). `context`
 * carries whatever the product's targets need (Portify: the case id), so the kit never has to
 * know what a case is.
 */
final class ScratchpadViewer
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $meetingId,
        public readonly string $userId,
        public readonly array $context = [],
    ) {}
}
