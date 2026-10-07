<?php

namespace Shirahcan\CalendarClient\Ics;

use DateTimeInterface;

/**
 * One meeting as a calendar invite (Ics::build). A product maps its own record to this; the
 * file format, escaping and folding are the kit's.
 */
final class IcsEvent
{
    /**
     * @param  string  $uid  stable for the meeting's life ("{ref}@{issuer-host}"), so updates replace it
     * @param  int  $sequence  must grow with every change of time or status, or calendar apps ignore the update
     * @param  'CONFIRMED'|'TENTATIVE'|'CANCELLED'  $status
     * @param  list<IcsPerson>  $attendees
     */
    public function __construct(
        public readonly string $uid,
        public readonly DateTimeInterface $start,
        public readonly DateTimeInterface $end,
        public readonly string $summary,
        public readonly int $sequence,
        public readonly DateTimeInterface $stamp,
        public readonly string $status = 'CONFIRMED',
        public readonly ?string $description = null,
        public readonly ?string $url = null,
        public readonly ?IcsPerson $organizer = null,
        public readonly array $attendees = [],
        public readonly ?int $alarmMinutesBefore = 15,
    ) {}
}
