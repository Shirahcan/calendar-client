<?php

namespace Shirahcan\CalendarClient;

use DateTimeImmutable;

/**
 * A booking as the owning product sees it. Store `id` against your own record (the case,
 * interview, call): it never changes, from hold through reschedules to completion.
 */
final class Booking
{
    public const HELD = 'held';
    public const PENDING = 'pending_approval';
    public const CONFIRMED = 'confirmed';
    public const DECLINED = 'declined';
    public const RELEASED = 'released';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';
    public const COMPLETED = 'completed';

    /**
     * @param list<string> $hosts
     * @param list<array> $participants
     */
    public function __construct(
        public readonly string $id,
        public readonly string $state,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly array $hosts,
        public readonly array $participants,
        public readonly ?DateTimeImmutable $holdExpiresAt,
        public readonly ?string $productRef,
        public readonly ?string $bookingType,
        public readonly ?array $openProposal,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) $a['id'],
            state: (string) $a['state'],
            start: new DateTimeImmutable($a['start_utc']),
            end: new DateTimeImmutable($a['end_utc']),
            hosts: array_values((array) ($a['hosts'] ?? [])),
            participants: array_values((array) ($a['participants'] ?? [])),
            holdExpiresAt: isset($a['hold_expires_at']) ? new DateTimeImmutable($a['hold_expires_at']) : null,
            productRef: $a['product_ref'] ?? null,
            bookingType: $a['booking_type'] ?? null,
            openProposal: $a['open_proposal'] ?? null,
            raw: $a,
        );
    }

    /** Still occupies (or may occupy) its time. */
    public function isLive(): bool
    {
        return in_array($this->state, [self::HELD, self::PENDING, self::CONFIRMED], true);
    }
}
