<?php

namespace Shirahcan\CalendarClient\Laravel\Bookings;

use Illuminate\Database\Eloquent\Model;

/**
 * For a model whose facts calendar-service holds (HeldBookingSubject). Use it on the model and
 * name the subject:
 *
 *     use HeldInCalendarService;
 *     protected static function calendarSubject(): string { return MeetingBookingSubject::class; }
 *
 * - Loading: every collection the model builds is hydrated from its bookings in ONE call, so the
 *   attributes ARE the service's values (and getOriginal / isDirty / wasChanged mean what they
 *   always meant).
 * - Saving: the held attributes are never written to the product's table; the AuthorityObserver
 *   has already sent them to the service, which refused or accepted them.
 *
 * A row WITHOUT a booking (a fixture, or a record from before the product's cutover not moved
 * yet) keeps its own columns: nothing else holds them.
 *
 * ⚠ `cursor()` and raw `DB::table()` reads bypass this (no collection is built). Read such a
 * model through Eloquent collections only.
 */
trait HeldInCalendarService
{
    /** @return class-string<HeldBookingSubject> */
    abstract protected static function calendarSubject(): string;

    public static function heldSubject(): HeldBookingSubject
    {
        return app(static::calendarSubject());
    }

    public function newCollection(array $models = [])
    {
        if ($models !== []) {
            static::hydrateHeld($models);
        }

        return parent::newCollection($models);
    }

    /**
     * Fill each record's held attributes from its booking (one call for all of them). Also the
     * way to re-read a record after its booking changed outside this request.
     *
     * @param array<int, Model> $models
     */
    public static function hydrateHeld(array $models): void
    {
        $s = static::heldSubject();
        $col = $s->column();
        $ids = [];
        foreach ($models as $m) {
            if ($m instanceof self && $m->exists && ($id = (string) ($m->getAttributes()[$col] ?? '')) !== '') {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        $bookings = app(HeldBookings::class)->load(array_keys($ids));
        foreach ($models as $m) {
            $id = $m instanceof self ? (string) ($m->getAttributes()[$col] ?? '') : '';
            if ($id === '' || ! $m->exists || $m->isDirty()) {
                continue;
            }
            $m->setRawAttributes(array_merge($m->getAttributes(), $s->hydrate($bookings[$id] ?? null, $m)), true);
        }
    }

    /** True once the record is held by a booking (its held columns are no longer its own). */
    public function isHeldInCalendarService(): bool
    {
        return (string) ($this->getAttributes()[static::heldSubject()->column()] ?? '') !== '';
    }

    protected function getDirtyForUpdate()
    {
        $dirty = parent::getDirtyForUpdate();
        if (! $this->isHeldInCalendarService()) {
            return $dirty;
        }

        $own = array_diff_key($dirty, array_flip(static::heldSubject()->heldAttributes()));
        // Only held facts changed (and the clock did not move a second): still a write, so the
        // model's `updated` listeners hear about it and wasChanged() answers.
        if ($own === [] && $dirty !== []) {
            return [$this->getKeyName() => $this->getKey()];
        }

        return $own;
    }

    protected function getAttributesForInsert()
    {
        $attributes = parent::getAttributesForInsert();

        return $this->isHeldInCalendarService()
            ? array_diff_key($attributes, array_flip(static::heldSubject()->heldAttributes()))
            : $attributes;
    }
}
