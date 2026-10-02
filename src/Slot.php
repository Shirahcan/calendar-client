<?php

namespace Shirahcan\CalendarClient;

use DateTimeImmutable;

/** One bookable slot, in UTC. `hostIds` lists the free hosts, preferred first. */
final class Slot
{
    /** @param list<string> $hostIds */
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly array $hostIds,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(new DateTimeImmutable($a['start_utc']), new DateTimeImmutable($a['end_utc']), array_values((array) ($a['host_ids'] ?? [])));
    }
}
