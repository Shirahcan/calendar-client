<?php

namespace Shirahcan\CalendarClient\Laravel\Bookings;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Shirahcan\CalendarClient\Booking;

/**
 * A booker's hold on a slot, as the token the product hands their browser.
 *
 * The hold itself is calendar-service's: the time, its expiry, its release and its confirm. A
 * product keeps NO row for it. What the product needs again when the booker confirms (which
 * browser session took it, and the product's own facts: who is booking, what they picked, the
 * zone they booked in) travels inside the token, encrypted and signed with the product's app
 * key: the browser carries it and can neither read nor alter it.
 *
 *   $token = SealedHold::for($hold, $sessionId, ['client_id' => ..., 'zone' => ...])->seal();
 *   $hold  = SealedHold::open($token);              // null for anything not sealed as a hold
 *   $hold?->belongsTo($sessionId);                  // only that browser may complete it
 *
 * ⚠ Rotating APP_KEY invalidates every open hold (they last minutes; the booker picks again).
 */
final class SealedHold
{
    private const PURPOSE = 'calendar-client.hold.v1';

    /** @param array<string, scalar|null> $context the product's own facts about this hold */
    public function __construct(
        public readonly string $bookingId,
        public readonly string $sessionId,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly array $context = [],
    ) {}

    /** @param array<string, scalar|null> $context */
    public static function for(Booking $hold, string $sessionId, array $context = []): self
    {
        return new self(
            $hold->id,
            $sessionId,
            CarbonImmutable::instance($hold->start),
            CarbonImmutable::instance($hold->end),
            $context,
        );
    }

    /** The token for the booker's browser. */
    public function seal(): string
    {
        return Crypt::encryptString(json_encode([
            'p' => self::PURPOSE,
            'b' => $this->bookingId,
            's' => $this->sessionId,
            'st' => $this->start->toIso8601String(),
            'en' => $this->end->toIso8601String(),
            'c' => $this->context,
        ], JSON_THROW_ON_ERROR));
    }

    /** The hold a token stands for, or null for anything that was not sealed as one. */
    public static function open(?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($data) || ($data['p'] ?? null) !== self::PURPOSE || ! is_string($data['b'] ?? null)) {
            return null;
        }

        return new self(
            $data['b'],
            (string) ($data['s'] ?? ''),
            CarbonImmutable::parse((string) $data['st']),
            CarbonImmutable::parse((string) $data['en']),
            is_array($data['c'] ?? null) ? $data['c'] : [],
        );
    }

    /** Whether the browser presenting it is the one that took the hold. */
    public function belongsTo(?string $sessionId): bool
    {
        return $sessionId !== null && $sessionId !== '' && hash_equals($this->sessionId, $sessionId);
    }

    /** One of the product's facts, or `$default`. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function durationMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }
}
