<?php

namespace Shirahcan\CalendarClient\Laravel\Bookings;

use Illuminate\Database\Eloquent\Model;
use Shirahcan\CalendarClient\Booking;

/**
 * A subject whose record's FACTS live in calendar-service only (single source of truth). The
 * product's row is a handle: its id, its links to the product's own things, and the booking id.
 * When, its state, its title and why it ended are the booking's, read on load and written there
 * first; the product's columns for them are never written (HeldInCalendarService does that).
 *
 * The record keeps behaving like any Eloquent model: loading hydrates the attributes from the
 * service in ONE call per collection, so `$m->status`, dirty tracking, `getOriginal()` and every
 * observer of the product work unchanged. Saving sends the change to the service FIRST (the
 * AuthorityObserver), and a refusal means nothing changed.
 */
interface HeldBookingSubject extends BookingSubject
{
    /** @return list<string> the model's attributes the service holds (never stored by the product) */
    public function heldAttributes(): array;

    /**
     * The held attributes' values for a record, from its booking, in the model's own terms (a
     * product status, a datetime in the model's storage format). `$b` is null when the service
     * no longer has the booking: say what such a record is (usually: it ended).
     *
     * @return array<string, mixed>
     */
    public function hydrate(?Booking $b, Model $m): array;

    /**
     * What the record's change says happened at the END of the meeting, or null: whether the
     * meeting is over (`complete`) and whether the person in `role` came (`attendance`), plus who
     * they are (`who`) so a booking without them can gain them.
     *
     * @return array{complete: bool, attendance?: ?string, role?: string, who?: array}|null
     */
    public function outcome(Model $m): ?array;

    /**
     * A participant's answer the record's change carries (the client confirming they will
     * attend), or null: `role`, `response` (accepted/declined/pending), `who`, `reason`.
     *
     * @return array{role: string, response: string, who?: array, reason?: ?string}|null
     */
    public function response(Model $m): ?array;

    /** Created waiting for the host's approval (a client's request): booked pending, approved or declined later. */
    public function startsPending(Model $m): bool;

    /** Why the record stopped occupying time (sent with the cancel or decline). */
    public function cancelReason(Model $m): ?string;

    /**
     * The booking's own text: what it is called, what it is about and where to join. Sent when
     * it is created and whenever a detail attribute changes (synchronously: it is read back).
     *
     * @return array{title?: ?string, description?: ?string, location?: ?string}
     */
    public function details(Model $m): array;

    /** @return list<array{auth_id?: ?string, email?: ?string, name?: ?string, role: string}> people on it who are not hosts */
    public function participants(Model $m): array;

    /**
     * The state a record that does NOT occupy time is created in (`completed`, `cancelled`,
     * `declined`), handed to the service as history. Null refuses it.
     */
    public function endedState(Model $m): ?string;
}
