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

    /*
     * What became of it (calendar-service 2026-10-13). `completed` only says the time passed;
     * the outcome says whether the call happened. Never read "held" from the state.
     */
    public const OUTCOME_UPCOMING = 'upcoming';
    public const OUTCOME_AWAITING = 'awaiting_outcome';
    public const OUTCOME_HELD = 'held';
    public const OUTCOME_NO_SHOW = 'no_show';
    public const OUTCOME_HOST_NO_SHOW = 'host_no_show';
    public const OUTCOME_NOBODY_CAME = 'nobody_came';
    public const OUTCOME_UNRECORDED = 'unrecorded';
    public const OUTCOME_CANCELLED = 'cancelled';
    public const OUTCOME_DECLINED = 'declined';
    public const OUTCOME_EXPIRED = 'expired';

    /** An ended booking with no attendance is awaiting its outcome this long, then unrecorded. */
    public const OUTCOME_GIVE_UP_HOURS = 72;

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

    /** What became of it, as the service says; derived the service's way when it did not say. */
    public function outcome(): string
    {
        return is_string($this->raw['outcome'] ?? null) ? $this->raw['outcome'] : self::deriveOutcome($this->raw);
    }

    /**
     * calendar-service's BookingOutcome rule, for a payload that does not carry `outcome` (an older
     * service, the fake). Keep it in step with the service's.
     */
    public static function deriveOutcome(array $a, ?int $now = null): string
    {
        $now ??= class_exists(\Carbon\Carbon::class) ? \Carbon\Carbon::now()->getTimestamp() : time();
        $state = (string) ($a['state'] ?? '');
        switch ($state) {
            case self::CANCELLED:
                return self::OUTCOME_CANCELLED;
            case self::DECLINED:
                return self::OUTCOME_DECLINED;
            case self::EXPIRED:
            case self::RELEASED:
                return self::OUTCOME_EXPIRED;
        }

        $end = isset($a['end_utc']) ? (new DateTimeImmutable((string) $a['end_utc']))->getTimestamp() : $now;
        if ($state !== self::COMPLETED && ! ($state === self::CONFIRMED && $end <= $now)) {
            return self::OUTCOME_UPCOMING;
        }

        $people = array_values((array) ($a['participants'] ?? []));
        $hosts = array_values((array) ($a['host_attendance'] ?? []));
        $recorded = array_filter($people, fn ($p) => ($p['attendance'] ?? null) !== null) !== [] || array_filter($hosts, fn ($h) => $h !== null) !== [];
        if (! $recorded) {
            return $end <= $now - self::OUTCOME_GIVE_UP_HOURS * 3600 ? self::OUTCOME_UNRECORDED : self::OUTCOME_AWAITING;
        }

        $bookerMissed = array_filter($people, fn ($p) => ($p['role'] ?? null) === 'booker' && ($p['attendance'] ?? null) === 'no_show') !== [];
        $hostCount = count((array) ($a['hosts'] ?? []));
        $hostsMissed = $hostCount > 0 && count(array_filter($hosts, fn ($h) => $h === 'no_show')) === $hostCount;

        return match (true) {
            $bookerMissed && $hostsMissed => self::OUTCOME_NOBODY_CAME,
            $bookerMissed => self::OUTCOME_NO_SHOW,
            $hostsMissed => self::OUTCOME_HOST_NO_SHOW,
            default => self::OUTCOME_HELD,
        };
    }

    /** Still occupies (or may occupy) its time. */
    public function isLive(): bool
    {
        return in_array($this->state, [self::HELD, self::PENDING, self::CONFIRMED], true);
    }
}
