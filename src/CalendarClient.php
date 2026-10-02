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

    public function reschedule(string $bookingId, DateTimeInterface $start, ?string $actor = null, ?string $reason = null): Booking;

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

    // Holidays (data an admin corrects without a deploy)

    /** @return list<array> */
    public function holidays(string $region, int $year): array;

    public function putHoliday(string $region, string $date, string $name): array;

    public function removeHoliday(string $region, string $date): array;
}
