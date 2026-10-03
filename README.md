# shirahcan/calendar-client

The thin PHP client every product backend uses to talk to **calendar-service** over
loopback. It owns no availability, busy time, slot engine or calendar tokens: if the
service is unreachable, booking fails closed with `CalendarServiceUnavailable`.

Design: `portify/docs/plans/calendar-service-2026-10-02/00_MASTER_INDEX.md`.

## Install

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/Shirahcan/calendar-client.git" }],
"require": { "shirahcan/calendar-client": "dev-main" }
```

```env
CALENDAR_SERVICE_URL=http://127.0.0.1:8010
CALENDAR_SERVICE_TRUST_KEY=      # php artisan calendar:issue-key <product>, on the service
CALENDAR_SERVICE_CALLBACK_SECRET= # php artisan calendar:set-callback <product> <url>, on the service
```

## Use

```php
$calendar = app(\Shirahcan\CalendarClient\CalendarClient::class);

$calendar->upsertHost($authId, 'Ada Lovelace', 'America/Toronto');
$calendar->upsertSchedule("consultant:{$id}", $authId, $spec);          // schema-1 spec
$calendar->upsertBookingType('consultation-30', [
    'duration' => 30, 'step' => 15, 'buffer_after' => 10, 'min_notice' => 'PT12H',
    'hosts' => ['mode' => 'single', 'members' => [['host' => $authId, 'schedule' => "consultant:{$id}"]]],
]);

$slots = $calendar->slots('consultation-30', $from, $to);
$hold = $calendar->hold('consultation-30', $slots[0]->start, $idempotencyKey, ['product_ref' => "case:{$caseId}"]);
$booking = $calendar->confirm($hold->id);   // store $booking->id on your record
```

Errors: `SlotUnavailable` (pick again), `HoldExpired` (start again), `CalendarNotFound`,
`CalendarRequestRejected` (`->errors` lists every problem), `CalendarServiceUnavailable`
(fail closed; never fall back to a local engine).

## The product-side kit (`Shirahcan\CalendarClient\Laravel`)

Every product wires calendar-service the same way. Do not write your own copy of any of these.

| Piece | Use |
|---|---|
| `Seam` | The one seam for booking time: `hold`, `confirm`, `extend`, `release`, `cancel`, `reschedule`, `createMeeting`, `setHosts`, `describe` (text + who receives the calendar copy), `promote`. Fails CLOSED; every failure is a `Refusal`. Extend it to add your product's meaning (which record maps to which ref, who it keeps busy). |
| `Refusal` | `getMessage()` is safe to show; `status()` is 409 taken / 503 unreachable / 422 rejected; `isRace()`. |
| `RendersRefusals::register($exceptions)` | One line in `bootstrap/app.php`: a Refusal on an API route answers `{success:false, message}` with its status, never a 500. |
| `CalendarKit::webhookRoute($uri)` | The signed-event receiver (outside auth middleware). Records each `event_id` once in `calendar_service_events` (the kit's migration; a no-op where the table exists) and fires `Events\CalendarServiceEventReceived` once. Listen with a queued listener. |

| `Spec\SpecBuilder::build($rows, $options)` | Your rule rows, normalized, to the schema-1 spec. Every product difference is an option (periods, windows past midnight, an empty override date closing, a buffer floor). |
| `SyncLedger::sync($type, $id, $payload, $notes, fn (CalendarClient $c) => ...)` | Send a subject only when it changed; `refuse()` records why something was not sent; failures are recorded and retried, never thrown into the save. Table `calendar_service_syncs`. |
| `Bookings\BookingSubject` (+ `AbstractBookingSubject`) | Your booking model's MEANING: its reference, who it keeps busy, when it occupies time, which fields mean a move / new people / new text, its calendar copy. |
| `Bookings\AuthorityObserver` | A one-line subclass naming your subject, observed on your model: the service becomes the authority for that model's time on every code path (book first and fail closed, move, end, reopen, delete, people joining, the calendar copy, pre-cutover records linked). Jobs: `PushDetails`, `SyncHosts`, `CancelBooking`, `VerifyLanded`. |

⚠ A booking reference passed to `createMeeting` is also its idempotency key, so it must exist
before your own insert (a uuid, never an auto-increment id).

⚠ Laravel stops an '-ing' model event at the first listener that RETURNS non-null. A model hook
written as an arrow function (or an observer method returning `true`) silently switches the
authority observer off. Write those hooks with a `void` block body.

## Webhooks (without the kit)

Verify with `WebhookSignature::verify($raw, $header['X-Calendar-Signature'], $header['X-Calendar-Timestamp'], $secret)`
and dedupe on the body's `event_id`.

## Tests in a product

```php
$fake = (new FakeCalendarClient())->withSlots('consultation-30', [new Slot($start, $end, ['auth-1'])]);
app()->instance(CalendarClient::class, $fake);
```

The fake keeps the service's contract (a held slot cannot be held again, an expired hold
cannot be confirmed), so a passing product test is not leaning on a promise the service
would refuse.
