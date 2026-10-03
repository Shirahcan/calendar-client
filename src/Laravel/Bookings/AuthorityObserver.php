<?php

namespace Shirahcan\CalendarClient\Laravel\Bookings;

use Illuminate\Database\Eloquent\Model;
use Shirahcan\CalendarClient\Laravel\Jobs\CancelBooking;
use Shirahcan\CalendarClient\Laravel\Jobs\PushDetails;
use Shirahcan\CalendarClient\Laravel\Jobs\SyncHosts;
use Shirahcan\CalendarClient\Laravel\Jobs\VerifyLanded;
use Shirahcan\CalendarClient\Laravel\Seam;

/**
 * calendar-service as the AUTHORITY for a product model's time, whichever code path writes it (K6).
 * A product registers a one-line subclass naming its subject:
 *
 *     class BookingAuthorityObserver extends AuthorityObserver
 *     {
 *         protected function subjectClass(): string { return MployNowBookingSubject::class; }
 *     }
 *     Booking::observe(BookingAuthorityObserver::class);
 *
 * - creating: booked in the service FIRST, for everyone it keeps busy; a refusal (taken, or the
 *   service unreachable) means the row is never written (fail closed).
 * - updating: a move is the hosts' move; leaving the busy states cancels; approving/declining a
 *   pending one approves/declines it; a record from before the cutover is linked on its first
 *   change (its mirror promoted, adopting the calendar events the product already wrote).
 * - deleted: cancelled. Ending never blocks on the service (CancelBooking retries).
 * - people joining or leaving follow it (SyncHosts); its calendar copy follows it (PushDetails).
 *
 * ⚠ Synchronous on purpose: an asynchronous write would let a record land on a time the service
 * then refuses, which is the double-booking the service exists to end.
 *
 * ⚠ CHECK THE MODEL'S OWN '-ing' LISTENERS. Laravel stops an '-ing' event (creating, updating,
 * deleting) at the first listener that RETURNS anything non-null, so a hook written as an arrow
 * function (`static::creating(fn ($m) => $m->uuid ??= ...)` returns the uuid) or an observer
 * method returning `true` silently switches this observer OFF. Portify's MeetingObserver shipped
 * exactly that once. Write such hooks with a `void` block body.
 */
abstract class AuthorityObserver
{
    /** @var array<string, true> records whose change the product's booking flow already sent */
    private static array $handled = [];

    /** @return class-string<BookingSubject> */
    abstract protected function subjectClass(): string;

    protected function subject(): BookingSubject
    {
        return app($this->subjectClass());
    }

    /** The product's flow sent this record's change itself (a hold it confirmed): skip it once. */
    public static function markHandled(Model $m): void
    {
        self::$handled[$m::class.'#'.$m->getKey()] = true;
    }

    private static function consumeHandled(Model $m): bool
    {
        $key = $m::class.'#'.$m->getKey();
        $was = isset(self::$handled[$key]);
        unset(self::$handled[$key]);

        return $was;
    }

    public function creating(Model $m): void
    {
        $s = $this->subject();
        $col = $s->column();
        if (! $s->enabled() || $m->getAttribute($col) || ! $s->occupies($m)) {
            return;
        }
        $hosts = $s->hosts($m);
        if ($hosts === []) {
            return;
        }

        $ref = $s->ref($m);
        // Throws Refusal: the record is not written.
        $m->setAttribute($col, (new Seam())->createMeeting($hosts, $s->start($m), $s->end($m), $ref)->id);

        // If the insert below fails (or its transaction rolls back), this gives the time back.
        // Not on `sync`: it would run NOW, before the insert, and cancel a good booking.
        if (config('queue.default') !== 'sync') {
            try {
                VerifyLanded::dispatch($this->subjectClass(), $ref, (string) $m->getAttribute($col))->delay(now()->addSeconds(VerifyLanded::DELAY_SECONDS));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function created(Model $m): void
    {
        $s = $this->subject();
        if ($s->enabled() && $m->getAttribute($s->column())) {
            PushDetails::enqueue($this->subjectClass(), $m->getKey());
            // A booking confirmed from a hold has the booking type's members as hosts: bring in
            // everyone the record keeps busy.
            SyncHosts::enqueue($this->subjectClass(), $m->getKey());
        }
    }

    public function updating(Model $m): void
    {
        $s = $this->subject();
        if (! $s->enabled() || self::consumeHandled($m)) {
            return;
        }

        $was = (clone $m)->setRawAttributes($m->getOriginal());
        $occupiedBefore = $s->occupies($was);
        $occupiesNow = $s->occupies($m);
        $moved = $m->isDirty($s->moveAttributes());
        $approval = $s->approval($m);
        if (! $moved && $approval === null && $occupiedBefore === $occupiesNow) {
            return;
        }

        $col = $s->column();
        $seam = new Seam();

        if (! $occupiesNow) {
            if ($occupiedBefore && ($id = $m->getAttribute($col))) {
                $approval === 'decline'
                    ? $this->quietly(fn () => $seam->decline($id, $s->actor()), $id)
                    : $this->quietly(fn () => $seam->cancel($id, 'product', $s->actor()), $id);
            }

            return;
        }

        // Reopened (it had stopped occupying time, now it does again): its old service booking is
        // ended, and its reference is that booking's idempotency key, so it is booked afresh under
        // a reopen key. Throws Refusal when the time has been taken meanwhile.
        if (! $occupiedBefore && $m->getAttribute($col)) {
            $hosts = $s->hosts($m);
            if ($hosts !== []) {
                $m->setAttribute($col, $seam->createMeeting($hosts, $s->start($m), $s->end($m), $s->ref($m), [], $s->ref($m).':reopened:'.now()->getTimestamp())->id);
            }

            return;
        }

        if (! $m->getAttribute($col)) {
            $linked = $seam->link($s, $m);
            if ($linked === null) {
                return;
            }
            $m->setAttribute($col, $linked->id);
            $moved = $linked->start->getTimestamp() !== $s->start($m)->getTimestamp()
                || $linked->end->getTimestamp() !== $s->end($m)->getTimestamp();
        }

        if ($moved) {
            // Throws Refusal: the move is not written.
            $seam->reschedule($m->getAttribute($col), $s->start($m), $s->end($m), true, $s->actor());
        }
        if ($approval === 'approve') {
            $seam->approve($m->getAttribute($col), $s->actor());
        }
    }

    public function updated(Model $m): void
    {
        $s = $this->subject();
        if (! $s->enabled() || ! $m->getAttribute($s->column())) {
            return;
        }
        if ($m->wasChanged(array_merge($s->detailAttributes(), $s->peopleAttributes(), [$s->column()]))) {
            PushDetails::enqueue($this->subjectClass(), $m->getKey());
        }
        if ($m->wasChanged($s->peopleAttributes())) {
            SyncHosts::enqueue($this->subjectClass(), $m->getKey());
        }
    }

    public function deleted(Model $m): void
    {
        $s = $this->subject();
        if ($s->enabled() && ($id = $m->getAttribute($s->column()))) {
            $this->quietly(fn () => (new Seam())->cancel($id, 'product', $s->actor().'-deleted'), $id);
        }
    }

    /** Ending never blocks on the service: the time only stays busy (safe), and is retried. */
    private function quietly(callable $fn, string $calendarBookingId): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
            CancelBooking::dispatch($calendarBookingId)->delay(now()->addMinute());
        }
    }
}
