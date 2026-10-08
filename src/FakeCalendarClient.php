<?php

namespace Shirahcan\CalendarClient;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Shirahcan\CalendarClient\Exceptions\CalendarNotFound;
use Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceException;
use Shirahcan\CalendarClient\Exceptions\HoldExpired;
use Shirahcan\CalendarClient\Exceptions\SlotUnavailable;

/**
 * In-memory stand-in for product tests: `app()->instance(CalendarClient::class, $fake)`.
 *
 * It keeps the CONTRACT the real service enforces (a held or booked slot cannot be held
 * again, an expired hold cannot be confirmed, states only move the legal way), so a
 * product test that passes here is not passing on a promise the service would refuse.
 * It does not compute availability: seed the slots with withSlots().
 */
class FakeCalendarClient implements CalendarClient
{
    /** @var array<string, list<Slot>> booking type ref => offered slots */
    private array $slots = [];

    /** @var array<string, array> id => booking array */
    public array $bookings = [];

    /** @var array<string, array> */
    public array $proposals = [];

    /** @var list<array{0:string,1:array}> every call, for assertions */
    public array $calls = [];

    public array $schedules = [];

    public array $bookingTypes = [];

    public array $hosts = [];

    /** @var array<string, array{hosts: list<string>, start: string, end: string}> ref => live mirror */
    public array $mirrors = [];

    /** @var list<array> every row handed to adoptConnections */
    public array $adopted = [];

    private ?CalendarServiceException $failNext = null;

    private ?DateTimeImmutable $now = null;

    /** @param list<Slot> $slots */
    public function withSlots(string $bookingTypeRef, array $slots): self
    {
        $this->slots[$bookingTypeRef] = $slots;

        return $this;
    }

    /** Make the next call throw, e.g. new CalendarServiceUnavailable('down') to test fail-closed. */
    public function failNext(CalendarServiceException $e): self
    {
        $this->failNext = $e;

        return $this;
    }

    public function travelTo(DateTimeInterface $now): self
    {
        $this->now = DateTimeImmutable::createFromInterface($now);

        return $this;
    }

    public function upsertHost(string $authId, ?string $name = null, ?string $zone = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->hosts[$authId] = ['auth_id' => $authId, 'name' => $name, 'zone' => $zone];
    }

    public function upsertSchedule(string $ref, ?string $hostAuthId, array $spec): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->schedules[$ref] = ['ref' => $ref, 'host' => $hostAuthId, 'spec' => $spec, 'spec_hash' => hash('sha256', json_encode($spec))];
    }

    public function schedule(string $ref): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->schedules[$ref] ?? throw new CalendarNotFound('Schedule not found.', null, 404);
    }

    public function schedules(array $refs): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return array_intersect_key($this->schedules, array_flip($refs));
    }

    public function deleteSchedule(string $ref): void
    {
        $this->log(__FUNCTION__, func_get_args());
        unset($this->schedules[$ref]);
    }

    public function upsertBookingType(string $ref, array $definition): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->bookingTypes[$ref] = ['ref' => $ref] + $definition;
    }

    public function slots(string $bookingTypeRef, DateTimeInterface $from, DateTimeInterface $to, ?int $durationMinutes = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        // The fake does not compute: seed slots of each length with withSlots(); a chosen length
        // returns only the seeded slots of that length.
        return array_values(array_filter(
            $this->slots[$bookingTypeRef] ?? [],
            fn (Slot $s) => $s->start >= $from && $s->start < $to && ! $this->taken($s->start->getTimestamp())
                && ($durationMinutes === null || $s->end->getTimestamp() - $s->start->getTimestamp() === $durationMinutes * 60)
        ));
    }

    public function previewSlots(array $spec, array $rules, DateTimeInterface $from, DateTimeInterface $to, ?string $hostAuthId = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return [];
    }

    public function hold(string $bookingTypeRef, DateTimeInterface $start, string $idempotencyKey, array $details = []): Booking
    {
        $this->log(__FUNCTION__, func_get_args());

        foreach ($this->bookings as $b) {
            if (($b['idempotency_key'] ?? null) === $idempotencyKey) {
                return Booking::fromArray($b);
            }
        }

        $slot = null;
        foreach ($this->slots[$bookingTypeRef] ?? [] as $s) {
            if ($s->start->getTimestamp() === $start->getTimestamp()) {
                $slot = $s;
            }
        }
        if ($slot === null || $this->taken($start->getTimestamp())) {
            throw new SlotUnavailable('That time is no longer available. Please pick another.', 'slot_unavailable', 409);
        }

        $id = sprintf('%08x-0000-4000-8000-%012x', count($this->bookings) + 1, random_int(0, 0xFFFFFFFFFFFF));
        $this->bookings[$id] = [
            'id' => $id, 'state' => Booking::HELD, 'booking_type' => $bookingTypeRef, 'product_ref' => $details['product_ref'] ?? null,
            'start_utc' => $this->iso($slot->start), 'end_utc' => $this->iso($slot->end),
            'hold_expires_at' => $this->iso($this->now()->modify('+'.(int) ($details['ttl'] ?? 900).' seconds')),
            'hosts' => isset($details['host']) && in_array($details['host'], $slot->hostIds, true) ? [$details['host']] : array_slice($slot->hostIds, 0, 1),
            'participants' => $details['participants'] ?? [], 'idempotency_key' => $idempotencyKey, 'open_proposal' => null,
        ];

        return Booking::fromArray($this->bookings[$id]);
    }

    public function extendHold(string $bookingId, int $seconds): Booking
    {
        $b = $this->live($bookingId, Booking::HELD);
        $b['hold_expires_at'] = $this->iso((new DateTimeImmutable($b['hold_expires_at']))->modify("+{$seconds} seconds"));

        return $this->save($b);
    }

    public function release(string $bookingId, ?string $actor = null): Booking
    {
        return $this->move($bookingId, [Booking::HELD], Booking::RELEASED);
    }

    public function confirm(string $bookingId, ?string $actor = null): Booking
    {
        $this->live($bookingId, Booking::HELD);
        $needsApproval = (bool) ($this->bookingTypes[$this->bookings[$bookingId]['booking_type']]['approval']['required'] ?? false);

        return $this->move($bookingId, [Booking::HELD], $needsApproval ? Booking::PENDING : Booking::CONFIRMED);
    }

    public function approve(string $bookingId, ?string $actor = null): Booking
    {
        return $this->move($bookingId, [Booking::PENDING], Booking::CONFIRMED);
    }

    public function decline(string $bookingId, ?string $actor = null, ?string $reason = null): Booking
    {
        return $this->move($bookingId, [Booking::PENDING], Booking::DECLINED);
    }

    public function reschedule(string $bookingId, DateTimeInterface $start, ?string $actor = null, ?string $reason = null, ?DateTimeInterface $end = null, bool $hostOverride = false): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if (! in_array($b['state'], [Booking::CONFIRMED, Booking::PENDING], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot be moved.", 'invalid_state', 409);
        }
        if ($this->taken($start->getTimestamp(), $bookingId)) {
            throw new SlotUnavailable('That time is no longer available. Please pick another.', 'slot_unavailable', 409);
        }

        $length = $end !== null
            ? $end->getTimestamp() - $start->getTimestamp()
            : (new DateTimeImmutable($b['end_utc']))->getTimestamp() - (new DateTimeImmutable($b['start_utc']))->getTimestamp();
        $b['start_utc'] = $this->iso(DateTimeImmutable::createFromInterface($start));
        $b['end_utc'] = $this->iso((DateTimeImmutable::createFromInterface($start))->modify("+{$length} seconds"));

        return $this->save($b);
    }

    public function propose(string $bookingId, DateTimeInterface $start, string $proposedBy, ?string $actor = null, ?DateTimeInterface $expiresAt = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->find($bookingId);
        $id = 'p-'.(count($this->proposals) + 1);

        return $this->proposals[$id] = ['id' => $id, 'booking_id' => $bookingId, 'start_utc' => $this->iso(DateTimeImmutable::createFromInterface($start)), 'proposed_by' => $proposedBy, 'state' => 'open'];
    }

    public function acceptProposal(string $proposalId, ?string $actor = null): Booking
    {
        $p = $this->proposals[$proposalId] ?? throw new CalendarNotFound('Proposal not found.', null, 404);
        if ($p['state'] !== 'open') {
            throw new CalendarRequestRejected('This proposal is no longer open.', 'proposal_closed', 409);
        }
        $this->proposals[$proposalId]['state'] = 'accepted';

        return $this->reschedule($p['booking_id'], new DateTimeImmutable($p['start_utc']), $actor);
    }

    public function declineProposal(string $proposalId, ?string $actor = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->proposals[$proposalId]['state'] = 'declined';

        return $this->proposals[$proposalId];
    }

    public function cancel(string $bookingId, string $by, ?string $actor = null, ?string $reason = null): Booking
    {
        $b = $this->move($bookingId, [Booking::HELD, Booking::PENDING, Booking::CONFIRMED], Booking::CANCELLED);

        return $this->save(['cancelled_by' => $by, 'cancel_reason' => $reason] + $b->raw);
    }

    public function markAttendance(string $bookingId, int $participantId, string $status, ?string $actor = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return ['id' => $participantId, 'attendance' => $status];
    }

    public function createMeeting(array $hostAuthIds, DateTimeInterface $start, DateTimeInterface $end, string $idempotencyKey, array $details = []): Booking
    {
        $this->log(__FUNCTION__, func_get_args());

        // As the service: the same idempotency key returns the booking it already made...
        foreach ($this->bookings as $b) {
            if (($b['idempotency_key'] ?? null) === $idempotencyKey) {
                return Booking::fromArray($b);
            }
        }
        // ...and a host who is busy at ANY overlapping moment refuses it (not only an identical start).
        if ($this->overlapsFor($hostAuthIds, $start->getTimestamp(), $end->getTimestamp())) {
            throw new SlotUnavailable('That time is no longer available. Please pick another.', 'slot_unavailable', 409);
        }
        $id = sprintf('%08x-0000-4000-9000-%012x', count($this->bookings) + 1, random_int(0, 0xFFFFFFFFFFFF));

        return $this->save([
            'id' => $id, 'state' => Booking::CONFIRMED, 'kind' => 'host_created', 'start_utc' => $this->iso(DateTimeImmutable::createFromInterface($start)),
            'end_utc' => $this->iso(DateTimeImmutable::createFromInterface($end)), 'hosts' => $hostAuthIds, 'participants' => $details['participants'] ?? [],
            'idempotency_key' => $idempotencyKey, 'hold_expires_at' => null, 'open_proposal' => null,
        ]);
    }

    public function booking(string $bookingId): Booking
    {
        $this->log(__FUNCTION__, func_get_args());

        return Booking::fromArray($this->find($bookingId));
    }

    public function events(string $hostAuthId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $events = array_values(array_map(
            fn ($b) => ['kind' => 'booking', 'booking' => $b],
            array_filter($this->bookings, fn ($b) => in_array($hostAuthId, $b['hosts'], true) && in_array($b['state'], [Booking::HELD, Booking::PENDING, Booking::CONFIRMED], true))
        ));

        return ['events' => $events, 'stale_external' => false];
    }

    public function connectUrl(string $hostAuthId, string $provider, string $returnUrl): string
    {
        $this->log(__FUNCTION__, func_get_args());

        return "https://calendar.example.test/oauth/{$provider}/start?host=".rawurlencode($hostAuthId);
    }

    public function mirror(string $ref, array $hostAuthIds, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $this->log(__FUNCTION__, func_get_args());

        $this->mirrors[$ref] = ['hosts' => array_values($hostAuthIds), 'start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM)];

        return ['product_ref' => $ref, 'kind' => 'mirrored', 'state' => Booking::CONFIRMED] + $this->mirrors[$ref];
    }

    public function removeMirror(string $ref): void
    {
        $this->log(__FUNCTION__, func_get_args());
        unset($this->mirrors[$ref]);
    }

    public function promoteMirror(string $ref, array $externalEvents = []): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $m = $this->mirrors[$ref] ?? throw new CalendarNotFound('No mirror with that ref.', null, 404);
        unset($this->mirrors[$ref]);
        $id = 'b-'.(count($this->bookings) + 1);

        return $this->save([
            'id' => $id, 'product_ref' => $ref, 'booking_type' => null, 'kind' => 'host_created', 'state' => Booking::CONFIRMED,
            'start_utc' => $this->iso(new DateTimeImmutable($m['start'])), 'end_utc' => $this->iso(new DateTimeImmutable($m['end'])),
            'hold_expires_at' => null, 'hosts' => $m['hosts'], 'participants' => [], 'title' => null, 'location' => null,
            'cancelled_by' => null, 'cancel_reason' => null, 'write_status' => $externalEvents ? 'written' : null, 'open_proposal' => null,
        ]);
    }

    public function updateDetails(string $bookingId, ?string $title = null, ?string $description = null, ?string $location = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        foreach (['title' => $title, 'location' => $location] as $k => $v) {
            if ($v !== null) {
                $b[$k] = $v;
            }
        }

        return $this->save($b);
    }

    public function writeTo(string $bookingId, ?array $hostAuthIds): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if ($hostAuthIds !== null && array_diff($hostAuthIds, $b['hosts']) !== []) {
            throw new CalendarRequestRejected('write_hosts names somebody who is not a host of this booking.', 'validation_failed', 422);
        }
        $b['write_hosts'] = $hostAuthIds === null ? null : array_values($hostAuthIds);

        return $this->save($b);
    }

    public function setHosts(string $bookingId, array $hostAuthIds, bool $checkBusy = true, ?string $actor = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if ($hostAuthIds === []) {
            throw new CalendarRequestRejected('A booking needs at least one host; cancel it instead.', 'validation_failed', 422);
        }
        $added = array_values(array_diff($hostAuthIds, $b['hosts']));
        if ($checkBusy && $added !== []) {
            $start = (new DateTimeImmutable($b['start_utc']))->getTimestamp();
            $end = (new DateTimeImmutable($b['end_utc']))->getTimestamp();
            foreach ($this->bookings as $other) {
                if ($other['id'] !== $bookingId && in_array($other['state'], [Booking::HELD, Booking::PENDING, Booking::CONFIRMED], true)
                    && array_intersect($added, $other['hosts']) !== []
                    && (new DateTimeImmutable($other['start_utc']))->getTimestamp() < $end
                    && (new DateTimeImmutable($other['end_utc']))->getTimestamp() > $start) {
                    throw new SlotUnavailable('That time is no longer available.', 'slot_unavailable', 409);
                }
            }
        }
        $b['hosts'] = array_values(array_unique($hostAuthIds));

        return $this->save($b);
    }

    public function adoptConnections(array $rows): array
    {
        $this->log(__FUNCTION__, [count($rows).' rows']);   // never the tokens
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            $this->adopted[] = $row;
            $out[] = ['index' => $i, 'outcome' => 'created'];
        }

        return ['adopted' => count($out), 'revived' => 0, 'kept' => 0, 'skipped' => 0, 'rows' => $out];
    }

    public function connections(string $hostAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return [];
    }

    public function updateConnection(int $connectionId, string $hostAuthId, ?array $busyCalendars = null, ?string $writeCalendar = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return ['id' => $connectionId];
    }

    public function disconnect(int $connectionId, string $hostAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return ['id' => $connectionId, 'status' => 'revoked'];
    }

    /** @var array<string, array> "REGION|Y-m-d" => holiday row, the service's shape */
    private array $holidayRows = [];

    public function holidays(string $region, int $year, array $include = []): array
    {
        $this->log(__FUNCTION__, func_get_args());

        $statuses = array_merge(['confirmed'], $include);
        $rows = array_filter($this->holidayRows, fn ($h) => $h['region'] === $region
            && str_starts_with($h['date'], (string) $year)
            && in_array($h['status'], $statuses, true));
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return array_values($rows);
    }

    /** @var list<int>|null null = the service default */
    public ?array $reminderOffsets = null;

    public function reminderPolicy(): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->reminderPayload();
    }

    public function setReminderPolicy(?array $offsetsMinutes, ?string $by = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        if ($offsetsMinutes === null) {
            $this->reminderOffsets = null;

            return $this->reminderPayload();
        }
        $clean = array_values(array_unique(array_filter(array_map('intval', $offsetsMinutes), fn (int $m) => $m >= 1 && $m <= 10080)));
        rsort($clean);
        $this->reminderOffsets = $clean;

        return $this->reminderPayload();
    }

    private function reminderPayload(): array
    {
        return [
            'offsets_minutes' => $this->reminderOffsets ?? [1440, 60, 30],
            'is_default' => $this->reminderOffsets === null,
            'default_offsets_minutes' => [1440, 60, 30],
        ];
    }

    /** The service's defaults (calendar-service config scheduling.defaults). */
    public const SCHEDULING_DEFAULTS = [
        'min_buffer_minutes' => 0,
        'max_buffer_minutes' => 240,
        'default_buffer_minutes' => 0,
        'buffer_choices' => [0, 5, 10, 15, 20, 30, 45, 60],
        'min_notice_minutes' => 0,
        'horizon_days' => 60,
        'hold_seconds' => 900,
        'seed_weekly' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'start' => '09:00', 'end' => '17:00']],
        'observe_holidays_by_default' => true,
    ];

    /** @var array<string, mixed>|null null = the service defaults */
    public ?array $schedulingPolicy = null;

    public function schedulingPolicy(): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->schedulingPayload();
    }

    public function setSchedulingPolicy(?array $policy, ?string $by = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->schedulingPolicy = $policy === null ? null : array_intersect_key($policy, self::SCHEDULING_DEFAULTS);

        return $this->schedulingPayload();
    }

    private function schedulingPayload(): array
    {
        return [
            'policy' => array_merge(self::SCHEDULING_DEFAULTS, $this->schedulingPolicy ?? []),
            'is_default' => $this->schedulingPolicy === null,
            'defaults' => self::SCHEDULING_DEFAULTS,
        ];
    }

    public function bookableRefs(?array $refs = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $today = $this->now()->format('Y-m-d');
        $live = fn (array $rows) => array_filter($rows, fn ($r) => ($r['valid_until'] ?? null) === null || $r['valid_until'] >= $today) !== [];
        $out = [];
        foreach ($this->schedules as $ref => $row) {
            if ($refs !== null && ! in_array($ref, $refs, true)) {
                continue;
            }
            $spec = (array) ($row['spec'] ?? []);
            $periods = array_filter((array) ($spec['periods'] ?? []), fn ($p) => ($p['to'] ?? '') >= $today && $live((array) ($p['weekly'] ?? [])));
            if ($live((array) ($spec['weekly'] ?? [])) || $periods !== []) {
                $out[] = (string) $ref;
            }
        }

        return $out;
    }

    /** @var array<string, array{code:string,name:string,active:bool,research:?array}> */
    public array $holidayRegionRows = [
        'CA' => ['code' => 'CA', 'name' => 'Canada', 'active' => true, 'research' => null],
        'GB' => ['code' => 'GB', 'name' => 'United Kingdom', 'active' => true, 'research' => null],
        'GH' => ['code' => 'GH', 'name' => 'Ghana', 'active' => true, 'research' => ['holidays' => ['Eid ul-Fitr', 'Eid al-Adha'], 'domains' => ['timeanddate.com']]],
        'NG' => ['code' => 'NG', 'name' => 'Nigeria', 'active' => true, 'research' => ['holidays' => ['Eid al-Fitr', 'Eid al-Adha', 'Id el-Maulud'], 'domains' => ['timeanddate.com']]],
        'US' => ['code' => 'US', 'name' => 'United States', 'active' => true, 'research' => null],
    ];

    /** @var array<string, list<array>> region => rules (the fake computes only `fixed` dates) */
    public array $holidayDefinitionRows = [];

    public function holidayRegions(): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $rows = array_values($this->holidayRegionRows);
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    public function putHolidayRegion(string $code, string $name, bool $active = true, ?string $by = null, array|null|false $research = false): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $keep = $this->holidayRegionRows[$code]['research'] ?? null;
        $this->holidayRegionRows[$code] = ['code' => $code, 'name' => $name, 'active' => $active, 'research' => $research === false ? $keep : $research];

        return $this->holidayRegions();
    }

    public function holidayDefinitions(string $region): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return array_values($this->holidayDefinitionRows[$region] ?? []);
    }

    public function holidayPreview(string $region, int $year): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $out = [];
        foreach ($this->holidayDefinitionRows[$region] ?? [] as $d) {
            if ($d['kind'] === 'fixed' && ($d['active'] ?? true)) {
                $out[] = ['date' => sprintf('%04d-%02d-%02d', $year, $d['params']['month'], $d['params']['day']), 'name' => $d['name']];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    public function saveHolidayDefinition(string $region, ?int $id, array $definition, ?string $by = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $rows = $this->holidayDefinitionRows[$region] ?? [];
        $id ??= 1 + max([0, ...array_column($rows, 'id')]);
        $row = ['id' => $id, 'name' => $definition['name'], 'kind' => $definition['kind'], 'params' => $definition['params'], 'active' => $definition['active'] ?? true, 'next' => null];
        $rows = array_values(array_filter($rows, fn ($r) => $r['id'] !== $id));
        $rows[] = $row;
        usort($rows, fn ($a, $b) => $a['id'] <=> $b['id']);
        $this->holidayDefinitionRows[$region] = $rows;

        return $rows;
    }

    public function deleteHolidayDefinition(string $region, int $id): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->holidayDefinitionRows[$region] = array_values(array_filter($this->holidayDefinitionRows[$region] ?? [], fn ($r) => $r['id'] !== $id));

        return $this->holidayDefinitionRows[$region];
    }

    public function putHoliday(string $region, string $date, string $name, ?string $by = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->holidayRows["{$region}|{$date}"] = $this->holidayRow($region, $date, $name, 'manual', true, 'confirmed') + ['decided_by' => $by];
    }

    public function removeHoliday(string $region, string $date, ?string $by = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        $name = $this->holidayRows["{$region}|{$date}"]['name'] ?? 'Removed';

        return $this->holidayRows["{$region}|{$date}"] = $this->holidayRow($region, $date, $name, 'manual', false, 'confirmed') + ['decided_by' => $by];
    }

    public function proposeHoliday(string $region, string $date, string $name, bool $estimated = false, array $sources = [], string $source = 'research'): array
    {
        $this->log(__FUNCTION__, func_get_args());

        foreach ($this->holidayRows as $row) {
            $sameDay = $row['region'] === $region && $row['date'] === $date;
            $sameYearName = $row['region'] === $region && substr($row['date'], 0, 4) === substr($date, 0, 4) && $row['name'] === $name;
            if (($sameDay || $sameYearName) && $row['status'] !== 'proposed') {
                return $row;
            }
        }

        return $this->holidayRows["{$region}|{$date}"] = $this->holidayRow($region, $date, $name, $source, true, 'proposed', $estimated, $sources);
    }

    public function confirmHoliday(string $region, string $date, ?string $by = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->decideHoliday($region, $date, 'confirmed', $by);
    }

    public function rejectHoliday(string $region, string $date, ?string $by = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->decideHoliday($region, $date, 'rejected', $by);
    }

    private function decideHoliday(string $region, string $date, string $status, ?string $by): array
    {
        $key = "{$region}|{$date}";
        if (($this->holidayRows[$key]['status'] ?? null) !== 'proposed') {
            throw new CalendarRequestRejected('Only a proposed holiday can be confirmed or rejected.', 'not_proposed', 409);
        }
        $this->holidayRows[$key]['status'] = $status;
        $this->holidayRows[$key]['decided_by'] = $by;

        return $this->holidayRows[$key];
    }

    private function holidayRow(string $region, string $date, string $name, string $source, bool $observed, string $status, bool $estimated = false, array $sources = []): array
    {
        return compact('region', 'date', 'name', 'source', 'observed', 'status', 'estimated', 'sources') + ['decided_by' => null];
    }

    /** @return list<array> the arguments of every call to $method */
    public function callsTo(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $method)));
    }

    private function log(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];

        if ($this->failNext !== null) {
            $e = $this->failNext;
            $this->failNext = null;

            throw $e;
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function iso(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function find(string $id): array
    {
        return $this->bookings[$id] ?? throw new CalendarNotFound('Booking not found.', null, 404);
    }

    private function save(array $b): Booking
    {
        $this->bookings[$b['id']] = $b;

        return Booking::fromArray($b);
    }

    private function live(string $id, string $state): array
    {
        $this->log('live', [$id]);
        $b = $this->find($id);
        if ($b['state'] !== $state) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot do that.", 'invalid_state', 409);
        }
        if ($state === Booking::HELD && (new DateTimeImmutable($b['hold_expires_at'])) <= $this->now()) {
            throw new HoldExpired('This hold has expired. Please pick a time again.', 'hold_expired', 410);
        }

        return $b;
    }

    /** @param list<string> $from */
    private function move(string $id, array $from, string $to): Booking
    {
        $this->log('move', [$id, $to]);
        $b = $this->find($id);
        if (! in_array($b['state'], $from, true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot become {$to}.", 'invalid_state', 409);
        }
        $b['state'] = $to;
        if ($to !== Booking::HELD) {
            $b['hold_expires_at'] = null;
        }

        return $this->save($b);
    }

    /** Is this start already occupied by a live booking (ignoring `$except`)? */
    /** Is any of these hosts in a live booking overlapping [start, end)? (the service's busy check) */
    private function overlapsFor(array $hostAuthIds, int $start, int $end, ?string $except = null): bool
    {
        foreach ($this->bookings as $id => $b) {
            if ($id === $except || ! in_array($b['state'], [Booking::HELD, Booking::PENDING, Booking::CONFIRMED], true)) {
                continue;
            }
            if ($b['state'] === Booking::HELD && $b['hold_expires_at'] !== null && new DateTimeImmutable($b['hold_expires_at']) <= $this->now()) {
                continue;
            }
            if (array_intersect($hostAuthIds, $b['hosts'] ?? []) === []) {
                continue;
            }
            if ((new DateTimeImmutable($b['start_utc']))->getTimestamp() < $end && (new DateTimeImmutable($b['end_utc']))->getTimestamp() > $start) {
                return true;
            }
        }

        return false;
    }

    private function taken(int $start, ?string $except = null): bool
    {
        foreach ($this->bookings as $id => $b) {
            if ($id === $except || ! in_array($b['state'], [Booking::HELD, Booking::PENDING, Booking::CONFIRMED], true)) {
                continue;
            }
            if ($b['state'] === Booking::HELD && $b['hold_expires_at'] !== null && new DateTimeImmutable($b['hold_expires_at']) <= $this->now()) {
                continue;
            }
            if ((new DateTimeImmutable($b['start_utc']))->getTimestamp() === $start) {
                return true;
            }
        }

        return false;
    }
}
