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

## Webhooks

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
