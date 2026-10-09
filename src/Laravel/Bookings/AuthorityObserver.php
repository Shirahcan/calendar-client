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
 * A HeldBookingSubject goes further: the service is the ONLY place its facts live (the product's
 * row is a handle, see HeldInCalendarService). Its details and people are sent with the create;
 * a record created already ended is handed over as history; ending, completing and attendance are
 * sent synchronously and a refusal stops the write, because no product copy would remember it.
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
        $held = $s instanceof HeldBookingSubject;
        if ($held && $s->enabled() && ($id = (string) $m->getAttribute($col)) !== '') {
            // Booked already (the product's flow confirmed a hold): the record's own text is the
            // booking's, and nothing else will carry it there. Throws Refusal: not written.
            $d = $s->details($m);
            if ($d !== []) {
                app(HeldBookings::class)->put((new Seam())->describe($id, $d['title'] ?? null, $d['description'] ?? null, $d['location'] ?? null));
            }

            return;
        }
        if (! $s->enabled() || $m->getAttribute($col) || (! $held && ! $s->occupies($m))) {
            return;
        }
        $hosts = $s->hosts($m);
        if ($hosts === []) {
            return;
        }

        $ref = $s->ref($m);
        if ($held && ! $s->occupies($m)) {
            // Already over when written (a past call recorded after the fact): history, not time.
            $state = $s->endedState($m) ?? throw new \LogicException(class_basename($m).' was written already ended, and its subject names no state to hand the service.');
            $booking = (new Seam())->import(array_filter([
                'idempotency_key' => $ref, 'product_ref' => $ref, 'hosts' => $hosts, 'state' => $state,
                'start' => $s->start($m)->toIso8601ZuluString(), 'end' => $s->end($m)->toIso8601ZuluString(),
                'participants' => $s->participants($m), 'cancel_reason' => $s->cancelReason($m),
            ] + $s->details($m), fn ($v) => $v !== null));
            $m->setAttribute($col, $booking->id);
            app(HeldBookings::class)->put($booking);

            return;
        }

        $details = $held ? array_filter($s->details($m) + ['participants' => $s->participants($m)], fn ($v) => $v !== null) : [];
        // Throws Refusal: the record is not written.
        $booking = (new Seam())->createMeeting($hosts, $s->start($m), $s->end($m), $ref, $details);
        $m->setAttribute($col, $booking->id);
        if ($held) {
            app(HeldBookings::class)->put($booking);
        }

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
            if (! $s instanceof HeldBookingSubject) {
                PushDetails::enqueue($this->subjectClass(), $m->getKey());   // held: sent with the create
            }
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

        if ($s instanceof HeldBookingSubject && $m->getAttribute($s->column())) {
            $this->updatingHeld($s, $m);

            return;
        }

        $was = (clone $m)->setRawAttributes($m->getRawOriginal());
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
            if ($s instanceof HeldBookingSubject) {
                // From here the record is held: its text must be on the booking before its own
                // columns stop being written.
                $d = $s->details($m);
                if ($d !== []) {
                    $seam->describe($linked->id, $d['title'] ?? null, $d['description'] ?? null, $d['location'] ?? null);
                }
                app(HeldBookings::class)->forget($linked->id);
            }
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
        if (! $s instanceof HeldBookingSubject && $m->wasChanged(array_merge($s->detailAttributes(), $s->peopleAttributes(), [$s->column()]))) {
            PushDetails::enqueue($this->subjectClass(), $m->getKey());   // held: sent in updating
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

    /**
     * A held record's change, sent FIRST and in full: nothing the product keeps would remember a
     * change the service did not take, so every refusal (Refusal) stops the write.
     */
    private function updatingHeld(HeldBookingSubject $s, Model $m): void
    {
        $id = (string) $m->getAttribute($s->column());
        $seam = new Seam();
        $cache = app(HeldBookings::class);
        $was = (clone $m)->setRawAttributes($m->getRawOriginal());
        $occupiedBefore = $s->occupies($was);
        $occupiesNow = $s->occupies($m);
        $approval = $s->approval($m);
        $last = null;

        try {
            if ($occupiedBefore && ! $occupiesNow) {
                $outcome = $s->outcome($m);
                if ($outcome !== null) {
                    $last = $this->conclude($seam, $s, $m, $id, $outcome);
                } elseif ($approval === 'decline') {
                    $last = $seam->decline($id, $s->actor(), $s->cancelReason($m));
                } else {
                    $seam->cancel($id, 'product', $s->actor(), $s->cancelReason($m));
                }

                return;
            }

            if (! $occupiedBefore && $occupiesNow) {
                // Reopened: its old booking ended, so it is booked afresh (Refusal when taken).
                $hosts = $s->hosts($m);
                if ($hosts !== []) {
                    $last = $seam->createMeeting($hosts, $s->start($m), $s->end($m), $s->ref($m),
                        array_filter($s->details($m) + ['participants' => $s->participants($m)], fn ($v) => $v !== null),
                        $s->ref($m).':reopened:'.now()->getTimestamp());
                    $m->setAttribute($s->column(), $last->id);
                }

                return;
            }

            if (! $occupiesNow) {
                // Already over: only a changed outcome (attendance corrected after the fact).
                $outcome = $s->outcome($m);
                if ($outcome !== null) {
                    $last = $this->conclude($seam, $s, $m, $id, $outcome);
                }

                return;
            }

            if ($m->isDirty($s->moveAttributes())) {
                $last = $seam->reschedule($id, $s->start($m), $s->end($m), true, $s->actor());
            }
            if ($approval === 'approve') {
                $last = $seam->approve($id, $s->actor());
            }
            if ($m->isDirty($s->detailAttributes())) {
                $d = $s->details($m);
                $last = $seam->describe($id, $d['title'] ?? null, $d['description'] ?? null, $d['location'] ?? null);
            }
        } finally {
            $cache->forget($id);
            if ($last !== null) {
                $cache->put($last);
            }
        }
    }

    /** It ended: completed (once started), then whether the person came. */
    private function conclude(Seam $seam, HeldBookingSubject $s, Model $m, string $id, array $outcome): ?\Shirahcan\CalendarClient\Booking
    {
        $booking = null;
        if ($outcome['complete'] ?? false) {
            $booking = $seam->complete($id, $s->actor());
        }
        if (($outcome['attendance'] ?? null) !== null) {
            $seam->attendance($id, $outcome['role'] ?? 'booker', $outcome['attendance'], (array) ($outcome['who'] ?? []), $s->actor());
            $booking = null;   // participants changed: read it again
        }

        return $booking;
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
