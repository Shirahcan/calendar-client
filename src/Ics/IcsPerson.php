<?php

namespace Shirahcan\CalendarClient\Ics;

/** An organizer or attendee on an invite. A person with no email is left off (no RSVP is possible). */
final class IcsPerson
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name = null,
        public readonly bool $required = true,
    ) {}
}
