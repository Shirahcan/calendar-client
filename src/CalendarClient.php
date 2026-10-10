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

    /**
     * Several of this product's schedules at once, keyed by ref; an unknown ref is absent.
     *
     * @param  list<string>  $refs
     * @return array<string, array{ref: string, host: ?string, spec: array}>
     */
    public function schedules(array $refs): array;

    public function deleteSchedule(string $ref): void;

    /** @param array $definition duration, step, buffers, notice, horizon, daily_cap, approval, hold_ttl, hosts{mode, members[{host, schedule}]} */
    public function upsertBookingType(string $ref, array $definition): array;

    /**
     * Several of this product's booking types in one call, keyed by ref (missing ones left out).
     *
     * @param list<string> $refs
     * @return array<string, array{ref: string, hosts: array, rules: array, approval: array, hold_ttl: ?int}>
     */
    public function bookingTypes(array $refs): array;

    // Booking links: a host's own ways to be booked, managed with or without the shared UI

    /**
     * A host's links as the service reads them back. buffer_* null = the host's usual buffer;
     * days_ahead null = the policy's horizon; daily_cap null = no limit.
     *
     * @return list<array{ref: string, host: string, name: string, description: ?string, color: ?string, slug: string, is_active: bool, is_default: bool, external_url: ?string, external_call_url: ?string, duration: ?int, buffer_before: ?int, buffer_after: ?int, daily_cap: ?int, days_ahead: ?int}>
     */
    public function links(string $hostAuthId): array;

    /**
     * Several hosts' links in one call (a list of consultants), grouped by host; every host asked
     * for is a key, with an empty list when they have none.
     *
     * @param  list<string>  $hostAuthIds
     * @return array<string, list<array>>
     */
    public function linksFor(array $hostAuthIds): array;

    /**
     * @param  array<string, mixed>  $fields  name and duration required; description, color, slug, is_active,
     *                                        is_default, external_url, external_call_url, buffer_before, buffer_after, daily_cap, days_ahead,
     *                                        schedule, ref optional
     *
     * @throws Exceptions\CalendarRequestRejected with every reason in `errors`
     */
    public function createLink(string $hostAuthId, array $fields): array;

    /** Only the keys given change. */
    public function updateLink(string $ref, array $fields): array;

    public function setDefaultLink(string $ref): array;

    /** The policy's starting links (`seed_links`) for a host who has none; a host with links keeps them. */
    public function seedLinks(string $hostAuthId, ?string $scheduleRef = null): array;

    public function deleteLink(string $ref): void;

    // Slots

    /**
     * `$durationMinutes`: a length the booking type offers in its `durations` (null = its
     * default). Pass the same length as `details['duration']` to hold().
     *
     * @return list<Slot>
     */
    public function slots(string $bookingTypeRef, DateTimeInterface $from, DateTimeInterface $to, ?int $durationMinutes = null): array;

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
     * @param string|null $by who moves it: a booker is held to the policy's reschedule notice
     *                        (refused as notice_window); host, product and system are not
     */
    public function reschedule(string $bookingId, DateTimeInterface $start, ?string $actor = null, ?string $reason = null, ?DateTimeInterface $end = null, bool $hostOverride = false, ?string $by = null): Booking;

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

    /**
     * Attendance for the participant in a ROLE (usually the booker). A booking without one gains
     * them from `$who` (auth_id / email / name), so a no-show can always be recorded.
     *
     * @param 'booker'|'attendee'|'guest' $role
     * @param 'attended'|'no_show'|'late' $status
     * @param array{auth_id?: ?string, email?: ?string, name?: ?string} $who
     */
    public function markAttendanceFor(string $bookingId, string $role, string $status, array $who = [], ?string $actor = null): array;

    /**
     * A participant's answer to a live booking, by role (accepted = they confirmed they will
     * attend). A booking without them gains them from `$who`.
     *
     * @param 'accepted'|'declined'|'pending' $response
     * @param array{auth_id?: ?string, email?: ?string, name?: ?string} $who
     */
    public function respond(string $bookingId, string $role, string $response, array $who = [], ?string $reason = null, ?string $actor = null): array;

    /*
     * A meeting's notes and scratchpads (plan N1): they belong to its booking.
     * A note: {id, booking_id, author_auth_id, content, visibility, source_ref, meta, created_at, updated_at}.
     * A pad: {booking_id, author_auth_id, content, updated_at, booking?}.
     */

    /** @return list<array<string, mixed>> the booking's notes; with `$viewer`, only what that person may read */
    public function notes(string $bookingId, ?string $viewerAuthId = null): array;

    /**
     * @param 'private'|'participants' $visibility
     * @param string|null $sourceRef the product's own id for an import (idempotent)
     */
    public function addNote(string $bookingId, string $authorAuthId, string $content, string $visibility = 'participants', ?string $sourceRef = null, ?array $meta = null, ?DateTimeInterface $createdAt = null): array;

    /** @param array{content?: string, visibility?: string, meta?: array} $fields meta keys merge */
    public function updateNote(string $noteId, array $fields): array;

    public function deleteNote(string $noteId): void;

    public function draft(string $bookingId, string $authorAuthId): array;

    public function saveDraft(string $bookingId, string $authorAuthId, string $content): array;

    public function discardDraft(string $bookingId, string $authorAuthId): void;

    /** @return list<array<string, mixed>> every pad this person has not filed or discarded, newest first, with its booking */
    public function pendingDrafts(string $authorAuthId): array;

    /** The meeting happened and is over (confirmed -> completed, once started; idempotent). */
    public function complete(string $bookingId, ?string $actor = null): Booking;

    /** A host's own meeting: no booking type, busy-only check, confirmed at once. @param list<string> $hostAuthIds */
    public function createMeeting(array $hostAuthIds, DateTimeInterface $start, DateTimeInterface $end, string $idempotencyKey, array $details = []): Booking;

    public function booking(string $bookingId): Booking;

    /**
     * Several of this product's bookings in one call (a calendar view, a list), keyed by id;
     * missing ones left out.
     *
     * @param  list<string>  $bookingIds
     * @return array<string, Booking>
     */
    public function bookings(array $bookingIds): array;

    /**
     * Record an existing meeting as it happened (past times, any terminal state, participants with
     * their answers and attendance, history). Idempotent by `idempotency_key`. The booking's
     * `raw` carries `history`.
     *
     * @param  array<string, mixed>  $meeting
     */
    public function importBooking(array $meeting): Booking;

    /**
     * This product's bookings by who, when and state. `$query`: hosts, participants, product_refs,
     * ids, states, kinds (lists), from, to (ISO instants; overlap), starts_from, starts_to (start within), ends_to (over by), order (asc|desc), limit,
     * offset, history (bool).
     *
     * @return array{data: list<Booking>, next: ?int, total: int}
     */
    public function searchBookings(array $query): array;

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

    /** @param 'google'|'microsoft'|'zoom' $provider zoom is a call tool, not a calendar */
    public function connectUrl(string $hostAuthId, string $provider, string $returnUrl): string;

    /** @return list<array> */
    public function connections(string $hostAuthId): array;

    public function updateConnection(int $connectionId, string $hostAuthId, ?array $busyCalendars = null, ?string $writeCalendar = null): array;

    public function disconnect(int $connectionId, string $hostAuthId): array;

    // Call tools (the person's own Zoom) and a booking's call link

    /** @return list<array{id: int, host: string, tool: string, account_email: ?string, status: string, last_error: ?string}> */
    public function callTools(string $hostAuthId): array;

    public function disconnectCallTool(int $callToolId, string $hostAuthId): array;

    /**
     * The booking's call link on the host's own tool, made once (asking again returns it). With no
     * tool, the host's own preference; null when they have none (the product's own room).
     *
     * @param 'zoom'|'google_meet'|null $tool
     * @return array{booking_id: string, tool: string, url: string}|null
     */
    public function callLink(string $bookingId, ?string $tool = null, ?string $hostAuthId = null): ?array;

    // The person's own preferences (one place for every product)

    /** @return array{call_tool: ?string, requires_approval: bool} */
    public function hostPreferences(string $hostAuthId): array;

    /**
     * @param array{call_tool?: ?string, requires_approval?: bool} $preferences only the keys given change
     * @return array{call_tool: ?string, requires_approval: bool}
     */
    public function setHostPreferences(string $hostAuthId, array $preferences): array;

    /**
     * Hand over existing connections (D6), 1 to 200 rows of {host_auth_id, provider,
     * account_email, access_token?, refresh_token, token_expires_at?, scopes?}. Loopback only:
     * the tokens never touch a file. @return array{adopted:int, revived:int, kept:int, skipped:int, rows:list<array{index:int, outcome:string}>}
     */
    public function adoptConnections(array $rows): array;

    // Holidays (data an admin corrects without a deploy)

    /**
     * Confirmed holidays; pass include ['proposed', 'rejected'] for the review list.
     *
     * @param  list<string>  $include
     * @return list<array>
     */
    public function holidays(string $region, int $year, array $include = []): array;

    /** Add, rename or confirm (a proposed date put by an admin is confirmed). $by names who. */
    public function putHoliday(string $region, string $date, string $name, ?string $by = null): array;

    public function removeHoliday(string $region, string $date, ?string $by = null): array;

    /**
     * A researched date waiting for a person. Never overwrites a decided holiday.
     *
     * @param  list<array{title?:string,url?:string,domain?:string}>  $sources
     */
    public function proposeHoliday(string $region, string $date, string $name, bool $estimated = false, array $sources = [], string $source = 'research'): array;

    public function confirmHoliday(string $region, string $date, ?string $by = null): array;

    public function rejectHoliday(string $region, string $date, ?string $by = null): array;

    // Booking reminders (when the service says `booking.reminder_due`; the product sends them)

    /** @return array{offsets_minutes: list<int>, is_default: bool, default_offsets_minutes: list<int>} */
    public function reminderPolicy(): array;

    /**
     * Minutes before the start, e.g. [1440, 60, 30]; [] = none; null = the service default.
     * $by names who changed it.
     *
     * @param  list<int>|null  $offsetsMinutes
     * @return array{offsets_minutes: list<int>, is_default: bool, default_offsets_minutes: list<int>}
     */
    public function setReminderPolicy(?array $offsetsMinutes, ?string $by = null): array;

    // Scheduling policy (buffer floor and ceiling, notice, horizon, hold, seed week), per product

    /** @return array{policy: array<string, mixed>, is_default: bool, defaults: array<string, mixed>} */
    public function schedulingPolicy(): array;

    /**
     * The whole policy (keys left out fall back to the service defaults); null = the defaults.
     *
     * @param  array<string, mixed>|null  $policy
     * @return array{policy: array<string, mixed>, is_default: bool, defaults: array<string, mixed>}
     */
    public function setSchedulingPolicy(?array $policy, ?string $by = null): array;

    /**
     * The calling product's schedule refs whose people can be booked (weekly hours now or ahead),
     * computed from the spec on every call. Pass refs to narrow it.
     *
     * @param  list<string>|null  $refs
     * @return list<string>
     */
    public function bookableRefs(?array $refs = null): array;

    // Holiday places and rules (data, shared by every product)

    /**
     * The places with public holidays. `research` lists the holidays no rule can give and the
     * sources a researcher may read for them (null = nothing to research).
     *
     * @return list<array{code:string, name:string, active:bool, research:?array{holidays:list<string>, domains:list<string>}}>
     */
    public function holidayRegions(): array;

    /**
     * Add or rename a place. $research: omit (false) to keep what it has, null to clear it.
     *
     * @param  array{holidays:list<string>, domains:list<string>}|null|false  $research
     * @return list<array{code:string, name:string, active:bool, research:?array}>
     */
    public function putHolidayRegion(string $code, string $name, bool $active = true, ?string $by = null, array|null|false $research = false): array;

    /** @return list<array{id:int, name:string, kind:string, params:array, active:bool, next:?string}> */
    public function holidayDefinitions(string $region): array;

    /** @return list<array{date:string, name:string}> */
    public function holidayPreview(string $region, int $year): array;

    /**
     * Add (id null) or change a rule; the place's dates follow at once.
     *
     * @param  array{name:string, kind:string, params:array, active?:bool}  $definition
     * @return list<array> the place's rules after the change
     */
    public function saveHolidayDefinition(string $region, ?int $id, array $definition, ?string $by = null): array;

    /** @return list<array> the place's rules after the change */
    public function deleteHolidayDefinition(string $region, int $id): array;
}
