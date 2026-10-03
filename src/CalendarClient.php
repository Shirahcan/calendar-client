<?php

namespace Shirahcan\CalendarClient;

use DateTimeInterface;

/**
 * Everything a product does with time goes through here. Every method throws a
 * Shirahcan\CalendarClient\Exceptions\CalendarServiceException subclass on failure:
 * SlotUnavailable, HoldExpired, CalendarNotFound, CalendarRequestRejected, or
 * CalendarServiceUnavailable (fail closed, D13).
 *
 * Refs are YOUR product's own (`consultant:42`); the service namespaces them by your key.
 */
interface CalendarClient
{
    // People, schedules, booking types

    public function upsertHost(string $authId, ?string $name = null, ?string $zone = null): array;

    /** @param array $spec schema-1 availability spec (portify docs/plans/calendar-service-2026-10-02/04) */
    public function upsertSchedule(string $ref, ?string $hostAuthId, array $spec): array;

    public function schedule(string $ref): array;

    public function deleteSchedule(string $ref): void;

    /** @param array $definition duration, step, buffers, notice, horizon, daily_cap, approval, hold_ttl, hosts{mode, members[{host, schedule}]} */
    public function upsertBookingType(string $ref, array $definition): array;

    // Slots

    /** @return list<Slot> */
    public function slots(string $bookingTypeRef, DateTimeInterface $from, DateTimeInterface $to): array;

    /** An inline spec: computed, never stored, never bookable. @return list<Slot> */
    public function previewSlots(array $spec, array $rules, DateTimeInterface $from, DateTimeInterface $to, ?string $hostAuthId = null): array;

    // Booking lifecycle

    /** @param array $details product_ref, host (preferred, round-robin), title, description, location, participants[], ttl, actor */
    public function hold(string $bookingTypeRef, DateTimeInterface $start, string $idempotencyKey, array $details = []): Booking;

    public function extendHold(string $bookingId, int $seconds): Booking;

    public function release(string $bookingId, ?string $actor = null): Booking;

    public function confirm(string $bookingId, ?string $actor = null): Booking;

    public function approve(string $bookingId, ?string $actor = null): Booking;

    public function decline(string $bookingId, ?string $actor = null, ?string $reason = null): Booking;

    /**
     * @param DateTimeInterface|null $end null keeps the booking's length
     * @param bool $hostOverride the HOST moves it: only free time is required, not an offered slot
     */
    public function reschedule(string $bookingId, DateTimeInterface $start, ?string $actor = null, ?string $reason = null, ?DateTimeInterface $end = null, bool $hostOverride = false): Booking;

    /** The product's text for the host's calendar copy; a confirmed booking's copy is rewritten. */
    public function updateDetails(string $bookingId, ?string $title = null, ?string $description = null, ?string $location = null): Booking;

    /**
     * Which hosts' external calendars receive the booking's copy: a subset of its hosts, or null
     * for every host (the default). For text written for ONE person (their own join link).
     *
     * @param list<string>|null $hostAuthIds
     */
    public function writeTo(string $bookingId, ?array $hostAuthIds): Booking;

    /**
     * Who the booking keeps busy, after somebody joins or leaves. Added hosts are checked against
     * their busy time unless `$checkBusy` is false. Throws SlotUnavailable when one is busy.
     *
     * @param list<string> $hostAuthIds
     */
    public function setHosts(string $bookingId, array $hostAuthIds, bool $checkBusy = true, ?string $actor = null): Booking;

    /** @param 'host'|'booker' $proposedBy */
    public function propose(string $bookingId, DateTimeInterface $start, string $proposedBy, ?string $actor = null, ?DateTimeInterface $expiresAt = null): array;

    public function acceptProposal(string $proposalId, ?string $actor = null): Booking;

    public function declineProposal(string $proposalId, ?string $actor = null): array;

    /** @param 'host'|'booker'|'product'|'system' $by */
    public function cancel(string $bookingId, string $by, ?string $actor = null, ?string $reason = null): Booking;

    /** @param 'attended'|'no_show'|'late' $status */
    public function markAttendance(string $bookingId, int $participantId, string $status, ?string $actor = null): array;

    /** A host's own meeting: no booking type, busy-only check, confirmed at once. @param list<string> $hostAuthIds */
    public function createMeeting(array $hostAuthIds, DateTimeInterface $start, DateTimeInterface $end, string $idempotencyKey, array $details = []): Booking;

    public function booking(string $bookingId): Booking;

    // Mirrors: a product's EXISTING meetings copied in as busy until its cutover (S1).
    // No availability check, no webhook, no write-back. Keyed by your ref.

    /** @param list<string> $hostAuthIds */
    public function mirror(string $ref, array $hostAuthIds, DateTimeInterface $start, DateTimeInterface $end): array;

    /** Idempotent: removing a mirror that is not there succeeds. */
    public function removeMirror(string $ref): void;

    /**
     * Cutover: the mirror becomes a host-created meeting the service owns. `$externalEvents`
     * ([{provider, account_email, event_id, calendar_id?}]) adopts calendar copies the product
     * already wrote, so they are updated instead of duplicated.
     */
    public function promoteMirror(string $ref, array $externalEvents = []): Booking;

    // Views

    /** Own bookings in full, everything else as anonymous busy. @return array{events: list<array>, stale_external: bool} */
    public function events(string $hostAuthId, DateTimeInterface $from, DateTimeInterface $to): array;

    // Calendar connections (the person's, shared by every product)

    /** @param 'google'|'microsoft' $provider */
    public function connectUrl(string $hostAuthId, string $provider, string $returnUrl): string;

    /** @return list<array> */
    public function connections(string $hostAuthId): array;

    public function updateConnection(int $connectionId, string $hostAuthId, ?array $busyCalendars = null, ?string $writeCalendar = null): array;

    public function disconnect(int $connectionId, string $hostAuthId): array;

    /**
     * Hand over existing connections (D6), 1 to 200 rows of {host_auth_id, provider,
     * account_email, access_token?, refresh_token, token_expires_at?, scopes?}. Loopback only:
     * the tokens never touch a file. @return array{adopted:int, revived:int, kept:int, skipped:int, rows:list<array{index:int, outcome:string}>}
     */
    public function adoptConnections(array $rows): array;

    // Holidays (data an admin corrects without a deploy)

    /** @return list<array> */
    public function holidays(string $region, int $year): array;

    public function putHoliday(string $region, string $date, string $name): array;

    public function removeHoliday(string $region, string $date): array;
}
