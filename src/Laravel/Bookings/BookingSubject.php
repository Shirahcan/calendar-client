<?php

namespace Shirahcan\CalendarClient\Laravel\Bookings;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * What a product's booking MEANS to calendar-service (K6): the product implements this once per
 * model that holds booked time (Portify's Meeting, MployNow's Booking, Studendly's call), and the
 * kit runs the whole lifecycle from it: create first and fail closed, move, end, people joining,
 * the calendar copy, the gate turned back off. Extend AbstractBookingSubject for the defaults.
 */
interface BookingSubject
{
    /** The product's gate that makes the service the authority for this model's time. */
    public function enabled(): bool;

    /** @return class-string<Model> */
    public function modelClass(): string;

    /** The model column holding the service's booking id. */
    public function column(): string;

    /** The product's reference (also the idempotency key). ⚠ Must exist BEFORE insert: a uuid. */
    public function ref(Model $m): string;

    /** The service booking linked to the record with this reference (trashed included), or null. */
    public function linkedByRef(string $ref): ?string;

    /** @return list<string> everyone it keeps busy (auth ids); [] = nothing to book */
    public function hosts(Model $m): array;

    /** Does it occupy time in its current state? (cancelled, completed... do not) */
    public function occupies(Model $m): bool;

    public function start(Model $m): CarbonInterface;

    public function end(Model $m): CarbonInterface;

    /** @return list<string> attributes whose change is a move */
    public function moveAttributes(): array;

    /** @return list<string> attributes whose change is a different set of people */
    public function peopleAttributes(): array;

    /** @return list<string> attributes whose change rewrites the calendar copy */
    public function detailAttributes(): array;

    /**
     * The calendar copy: ['title' => ?string, 'description' => ?string, 'location' => ?string,
     * 'write_to' => list<string>|null|false]. Null = leave the service's copy alone.
     */
    public function describe(Model $m): ?array;

    /** A state change that approves or declines a pending booking: 'approve' | 'decline' | null. */
    public function approval(Model $m): ?string;

    /** The S1 mirror reference of a record made before the cutover, or null. */
    public function mirrorRef(Model $m): ?string;

    /** @return list<array{provider: string, account_email: string, event_id: string, calendar_id?: ?string}> */
    public function externalEvents(Model $m): array;

    /** Refuse adding a person who is busy then? (false keeps a product's old behaviour; say why) */
    public function checkBusyOnJoin(): bool;

    /** The actor recorded on the service's history, e.g. 'mploynow:booking'. */
    public function actor(): string;
}
