<?php

namespace Shirahcan\CalendarClient\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Shirahcan\CalendarClient\Laravel\Models\CalendarServiceEvent;

/**
 * A new (never seen before) signed event from calendar-service was recorded (K4). Products listen
 * for this to act on it: an expired hold, a booking moved or cancelled in another product or a
 * connected calendar, a connection that needs re-authorising. Fired exactly once per `event_id`.
 *
 * ⚠ Listen with a QUEUED listener: the service retries on a slow answer, and the receiver must
 * answer quickly.
 */
class CalendarServiceEventReceived
{
    use Dispatchable;

    public function __construct(public readonly CalendarServiceEvent $event) {}
}
