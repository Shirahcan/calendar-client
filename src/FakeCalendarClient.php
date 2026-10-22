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
    /** The policy's cancel / reschedule notice, as the service enforces it (0 = none; tests set it). Both bind the booker only. */
    public int $cancelNoticeMinutes = 0;

    public int $rescheduleNoticeMinutes = 0;

    /** As the service: inside the notice the person is refused, with the sentence they read. */
    private function assertNotice(array $b, int $minutes, string $what, ?string $alternative = null): void
    {
        if ($minutes <= 0) {
            return;
        }
        $now = class_exists(\Carbon\Carbon::class) ? \Carbon\Carbon::now()->getTimestamp() : time();
        $start = strtotime((string) ($b['start_utc'] ?? ''));
        if ($start !== false && $start - $now < $minutes * 60) {
            $words = $minutes % 60 === 0 ? (intdiv($minutes, 60) === 1 ? '1 hour' : intdiv($minutes, 60).' hours') : "{$minutes} minutes";

            throw new CalendarRequestRejected("{$what} must be made at least {$words} before the meeting.", 'notice_window', 409, [], $alternative);
        }
    }

    /** @var array<string, list<Slot>> booking type ref => offered slots */
    private array $slots = [];

    /** @var array<string, array> id => booking array */
    public array $bookings = [];

    /** @var list<string> booking ids erased through eraseBooking() */
    public array $erased = [];

    /** @var list<array{email: ?string, auth_id: ?string}> people forgotten through forgetPerson() */
    public array $forgotten = [];

    /** @var array<string, array> */
    public array $proposals = [];

    /** @var list<array{0:string,1:array}> every call, for assertions */
    public array $calls = [];

    public array $schedules = [];

    public array $bookingTypes = [];

    public array $hosts = [];

    /** @var array<string, array{hosts: list<string>, start: string, end: string}> ref => live mirror */
    public array $mirrors = [];

    /** @var array<string, list<array<string, ?string>>> what happened to each booking, as the service records it */
    public array $histories = [];

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

    /**
     * As the service's minute sweep, for pending bookings nobody answered by their respond_by: each
     * becomes expired (its time free). Returns their ids, so a test can deliver the product the
     * `booking.approval_expired` webhook the service would send.
     *
     * @return list<string>
     */
    public function sweep(): array
    {
        $expired = [];
        foreach ($this->bookings as $id => $b) {
            if ($b['state'] === Booking::PENDING && ($b['respond_by'] ?? null) !== null && new DateTimeImmutable($b['respond_by']) <= $this->now()) {
                $this->record($id, 'approval_expired', Booking::PENDING, Booking::EXPIRED, 'system');
                $this->move($id, [Booking::PENDING], Booking::EXPIRED);
                $expired[] = $id;
            }
        }

        return $expired;
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
        unset($this->retiredTypes[$ref]);   // sending a type again offers it again

        return $this->bookingTypes[$ref] = ['ref' => $ref] + $definition;
    }

    /** @var array<string, true> refs of booking types the product retired */
    public array $retiredTypes = [];

    public function retireBookingType(string $ref): void
    {
        $this->log(__FUNCTION__, func_get_args());
        if (isset($this->bookingTypes[$ref])) {
            $this->retiredTypes[$ref] = true;
        }
    }

    /** @var array<string, array> ref => link, in the service's shape */
    public array $links = [];

    public function links(string $hostAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $rows = array_values(array_filter($this->links, fn ($l) => $l['host'] === $hostAuthId));
        usort($rows, fn ($a, $b) => [$b['is_default'], $a['name']] <=> [$a['is_default'], $b['name']]);

        return array_map(fn ($l) => $this->linkView($l), $rows);
    }

    public function linksFor(array $hostAuthIds): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $out = [];
        foreach ($hostAuthIds as $host) {
            $out[$host] = $this->links($host);
        }

        return $out;
    }

    public function createLink(string $hostAuthId, array $fields): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->assertLinkFields($fields, true);
        $ref = (string) ($fields['ref'] ?? 'link:'.bin2hex(random_bytes(8)));
        $fields['public_token'] = $this->linkToken($fields['public_token'] ?? null, null);
        $hasDefault = array_filter($this->links, fn ($l) => $l['host'] === $hostAuthId && $l['is_default']) !== [];
        $this->links[$ref] = $this->linkRow($ref, $hostAuthId, $fields + ['is_active' => true, 'is_default' => false]);
        $this->links[$ref]['is_default'] = false;
        if (($fields['is_default'] ?? false) || ! $hasDefault) {
            $this->setDefaultLink($ref);
        }

        return $this->linkView($this->links[$ref]);
    }

    public function updateLink(string $ref, array $fields): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $current = $this->links[$ref] ?? throw new CalendarNotFound('Link not found.', null, 404);
        $this->assertLinkFields($fields, false);
        if (array_key_exists('public_token', $fields)) {
            $fields['public_token'] = $this->linkToken($fields['public_token'], $current['public_token'] ?? null);
        }
        // Who made it is recorded once, as the service does.
        if (($current['created_by'] ?? null) !== null) {
            unset($fields['created_by']);
        }
        $default = $current['is_default'];
        $this->links[$ref] = $this->linkRow($ref, $current['host'], array_merge($current, $fields));
        $this->links[$ref]['is_default'] = $default;
        if (($fields['is_default'] ?? false) === true) {
            $this->setDefaultLink($ref);
        }

        return $this->linkView($this->links[$ref]);
    }

    /** @var list<string> every public token ever given out (a deleted link's is never reused) */
    private array $usedLinkTokens = [];

    /** As the service: true issues 64 random characters, a string is imported once, never replaced. */
    private function linkToken(mixed $asked, ?string $current): ?string
    {
        if ($current !== null) {
            if (is_string($asked) && $asked !== $current) {
                throw new CalendarRequestRejected('The link cannot be saved.', null, 422, ['This link already has a public token; it is never replaced.']);
            }

            return $current;
        }
        if ($asked === null || $asked === false) {
            return null;
        }
        if ($asked !== true && (! is_string($asked) || ! preg_match('/^[A-Za-z0-9]{32,80}$/', $asked))) {
            throw new CalendarRequestRejected('The link cannot be saved.', null, 422, ['A public token is 32 to 80 letters and digits (or true, for the service to issue one).']);
        }
        if (is_string($asked) && in_array($asked, $this->usedLinkTokens, true)) {
            throw new CalendarRequestRejected('The link cannot be saved.', null, 422, ['That public token already belongs to another link.']);
        }
        $token = $asked === true ? bin2hex(random_bytes(32)) : $asked;
        $this->usedLinkTokens[] = $token;

        return $token;
    }

    public function linkByToken(string $token): array
    {
        $this->log(__FUNCTION__, func_get_args());
        foreach ($this->links as $l) {
            if (($l['public_token'] ?? null) === $token) {
                return $this->linkView($l);
            }
        }

        throw new CalendarNotFound('Link not found.', null, 404);
    }

    public function seedLinks(string $hostAuthId, ?string $scheduleRef = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        if ($this->links($hostAuthId) === []) {
            $seeds = (array) (($this->schedulingPolicy ?? [])['seed_links'] ?? self::SCHEDULING_DEFAULTS['seed_links']);
            foreach ($seeds as $seed) {
                $this->createLink($hostAuthId, ['name' => $seed['name'], 'duration' => (int) $seed['duration'], 'is_default' => (bool) ($seed['is_default'] ?? false)]);
            }
        }

        return $this->links($hostAuthId);
    }

    public function setDefaultLink(string $ref): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $host = ($this->links[$ref] ?? throw new CalendarNotFound('Link not found.', null, 404))['host'];
        foreach ($this->links as $r => $l) {
            if ($l['host'] === $host) {
                $this->links[$r]['is_default'] = $r === $ref;
            }
        }

        return $this->linkView($this->links[$ref]);
    }

    public function deleteLink(string $ref): void
    {
        $this->log(__FUNCTION__, func_get_args());
        $link = $this->links[$ref] ?? throw new CalendarNotFound('Link not found.', null, 404);
        unset($this->links[$ref]);
        $next = array_key_first(array_filter($this->links, fn ($l) => $l['host'] === $link['host']));
        if ($link['is_default'] && $next !== null) {
            $this->setDefaultLink($next);
        }
    }

    private function linkRow(string $ref, string $host, array $f): array
    {
        $slug = $f['slug'] ?? null;
        $fromName = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $f['name'])), '-');

        return [
            'ref' => $ref, 'host' => $host, 'name' => (string) $f['name'], 'description' => $f['description'] ?? null,
            'color' => $f['color'] ?? null,
            'slug' => is_string($slug) && $slug !== '' ? $slug : ($fromName !== '' ? $fromName : 'link'),
            'is_active' => (bool) ($f['is_active'] ?? true), 'is_default' => (bool) ($f['is_default'] ?? false),
            'external_url' => $f['external_url'] ?? null, 'external_call_url' => $f['external_call_url'] ?? null, 'duration' => (int) $f['duration'],
            'buffer_before' => $f['buffer_before'] ?? null, 'buffer_after' => $f['buffer_after'] ?? null,
            'daily_cap' => $f['daily_cap'] ?? null, 'days_ahead' => $f['days_ahead'] ?? null,
            'expires_at' => ($f['expires_at'] ?? null) === null || $f['expires_at'] === ''
                ? null : $this->iso(new DateTimeImmutable((string) $f['expires_at'])),
            'max_bookings' => ($f['max_bookings'] ?? null) === null || $f['max_bookings'] === '' ? null : (int) $f['max_bookings'],
            'public_token' => is_string($f['public_token'] ?? null) ? $f['public_token'] : null,
            'created_by' => isset($f['created_by']) ? (string) $f['created_by'] : null,
        ];
    }

    /** A stored link as the service reads it back: with how many it has given out and why it is closed. */
    private function linkView(array $l): array
    {
        // A row a test wrote straight into $links may predate the limits: absent = none.
        $l += ['expires_at' => null, 'max_bookings' => null, 'public_token' => null, 'created_by' => null];

        return $l + [
            'bookings_used' => $l['max_bookings'] === null ? null : $this->linkBookingsUsed($l['ref']),
            'closed_reason' => $this->linkClosed($l['ref']),
        ];
    }

    /** As the service counts: a live hold, pending, confirmed or completed booking uses one up. */
    private function linkBookingsUsed(string $ref): int
    {
        $now = $this->now()->getTimestamp();

        return count(array_filter($this->bookings, fn ($b) => ($b['booking_type'] ?? null) === $ref
            && in_array($b['state'], [Booking::PENDING, Booking::CONFIRMED, Booking::COMPLETED, Booking::HELD], true)
            && ($b['state'] !== Booking::HELD || strtotime((string) ($b['hold_expires_at'] ?? '')) > $now)));
    }

    /** Why a link takes no bookings (inactive, expired, full), or null; null too for a type with no link. */
    private function linkClosed(string $ref): ?string
    {
        $l = $this->links[$ref] ?? null;

        return match (true) {
            $l === null => null,
            ! $l['is_active'] => 'inactive',
            ($l['expires_at'] ?? null) !== null && strtotime($l['expires_at']) <= $this->now()->getTimestamp() => 'expired',
            ($l['max_bookings'] ?? null) !== null && $this->linkBookingsUsed($ref) >= $l['max_bookings'] => 'full',
            default => null,
        };
    }

    /** The service's two most common refusals, so product tests see the real shape. */
    private function assertLinkFields(array $f, bool $creating): void
    {
        $errors = [];
        if (($creating || array_key_exists('name', $f)) && trim((string) ($f['name'] ?? '')) === '') {
            $errors[] = 'Give the link a name of up to 255 characters.';
        }
        $choices = array_map('intval', (array) (($this->schedulingPolicy ?? [])['duration_choices'] ?? self::SCHEDULING_DEFAULTS['duration_choices']));
        if (($creating || array_key_exists('duration', $f)) && ! in_array((int) ($f['duration'] ?? 0), $choices, true)) {
            $errors[] = 'Choose a length of '.implode(', ', $choices).' minutes.';
        }
        $expires = $f['expires_at'] ?? null;
        if ($expires !== null && $expires !== '' && strtotime((string) $expires) <= $this->now()->getTimestamp()) {
            $errors[] = 'An expiry date is in the future, or leave it empty for none.';
        }
        $cap = $f['max_bookings'] ?? null;
        if ($cap !== null && $cap !== '' && ((int) $cap < 1 || (int) $cap > 10000)) {
            $errors[] = 'A total limit is from 1 to 10000 bookings, or leave it empty for none.';
        }
        if ($errors !== []) {
            throw new CalendarRequestRejected('The link cannot be saved.', null, 422, $errors);
        }
    }

    /** In the service's shape: rules nested, as GET /v1/booking-types answers. */
    public function importBooking(array $meeting): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        foreach ($this->bookings as $b) {
            if (($b['idempotency_key'] ?? null) === $meeting['idempotency_key']) {
                return Booking::fromArray($b);
            }
        }
        $id = sprintf('%08x-0000-4000-8000-%012x', count($this->bookings) + 1, random_int(0, 0xFFFFFFFFFFFF));
        $this->bookings[$id] = [
            'id' => $id, 'state' => (string) $meeting['state'], 'kind' => 'imported', 'booking_type' => null,
            'product_ref' => $meeting['product_ref'] ?? null,
            'start_utc' => $this->iso(new DateTimeImmutable((string) $meeting['start'])),
            'end_utc' => $this->iso(new DateTimeImmutable((string) $meeting['end'])),
            'hold_expires_at' => null, 'hosts' => array_values((array) $meeting['hosts']),
            'host_attendance' => array_merge(array_fill_keys(array_values((array) $meeting['hosts']), null), (array) ($meeting['host_attendance'] ?? [])),
            'participants' => $this->withParticipantIds((array) ($meeting['participants'] ?? [])),
            'title' => $meeting['title'] ?? null, 'description' => $meeting['description'] ?? null,
            'location' => $meeting['location'] ?? null, 'cancelled_by' => $meeting['cancelled_by'] ?? null,
            'cancel_reason' => $meeting['cancel_reason'] ?? null, 'created_at' => $meeting['created_at'] ?? null,
            'history' => array_values((array) ($meeting['history'] ?? [])),
            'idempotency_key' => $meeting['idempotency_key'], 'open_proposal' => null,
        ];

        return Booking::fromArray($this->bookings[$id]);
    }

    public function searchBookings(array $query): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $in = fn (string $k, $v) => ! isset($query[$k]) || in_array($v, (array) $query[$k], true);
        $rows = array_values(array_filter($this->bookings, function (array $b) use ($query, $in) {
            $participants = array_column((array) ($b['participants'] ?? []), 'auth_id');

            return $in('ids', $b['id']) && $in('states', $b['state']) && $in('product_refs', $b['product_ref'] ?? null)
                && $in('outcomes', Booking::deriveOutcome($b))
                && (! isset($query['hosts']) || array_intersect((array) $query['hosts'], (array) $b['hosts']) !== [])
                && (! isset($query['participants']) || array_intersect((array) $query['participants'], $participants) !== [])
                && (! isset($query['from']) || new DateTimeImmutable($b['end_utc']) > new DateTimeImmutable((string) $query['from']))
                && (! isset($query['to']) || new DateTimeImmutable($b['start_utc']) < new DateTimeImmutable((string) $query['to']))
                && (! isset($query['starts_from']) || new DateTimeImmutable($b['start_utc']) >= new DateTimeImmutable((string) $query['starts_from']))
                && (! isset($query['starts_to']) || new DateTimeImmutable($b['start_utc']) <= new DateTimeImmutable((string) $query['starts_to']))
                && (! isset($query['ends_to']) || new DateTimeImmutable($b['end_utc']) <= new DateTimeImmutable((string) $query['ends_to']));
        }));
        usort($rows, fn ($a, $b) => (($query['order'] ?? 'asc') === 'desc' ? -1 : 1) * strcmp($a['start_utc'], $b['start_utc']));
        $offset = (int) ($query['offset'] ?? 0);
        $limit = (int) ($query['limit'] ?? 100);
        $page = array_slice($rows, $offset, $limit);

        return [
            'data' => array_map(fn (array $b) => Booking::fromArray($b), $page),
            'next' => $offset + count($page) < count($rows) ? $offset + count($page) : null,
            'total' => count($rows),
        ];
    }

    public function bookings(array $bookingIds): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $out = [];
        foreach ($bookingIds as $id) {
            if (isset($this->bookings[$id])) {
                $out[$id] = Booking::fromArray($this->bookings[$id]);
            }
        }

        return $out;
    }

    public function bookingTypes(array $refs): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $out = [];
        foreach ($refs as $ref) {
            if (! isset($this->bookingTypes[$ref])) {
                continue;
            }
            $d = $this->bookingTypes[$ref];
            $out[$ref] = [
                'ref' => $ref,
                'hosts' => $d['hosts'] ?? [],
                'rules' => array_intersect_key($d, array_flip(['duration', 'step', 'buffer_before', 'buffer_after', 'min_notice', 'horizon', 'daily_cap', 'grid', 'durations'])),
                'approval' => $d['approval'] ?? ['required' => false, 'blocks_slot' => true],
                'hold_ttl' => $d['hold_ttl'] ?? null,
            ];
        }

        return $out;
    }

    public function slots(string $bookingTypeRef, DateTimeInterface $from, DateTimeInterface $to, ?int $durationMinutes = null, ?array $since = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->slotsWithin($bookingTypeRef, $from, $to, $durationMinutes, $since)['slots'];
    }

    public function slotsOpening(string $bookingTypeRef, DateTimeInterface $from, DateTimeInterface $to, ?int $durationMinutes = null, ?array $since = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->slotsWithin($bookingTypeRef, $from, $to, $durationMinutes, $since);
    }

    /** @return array{slots: list<Slot>, opens_at: ?DateTimeImmutable} */
    private function slotsWithin(string $bookingTypeRef, DateTimeInterface $from, DateTimeInterface $to, ?int $durationMinutes, ?array $since): array
    {
        // The fake does not compute: seed slots of each length with withSlots(); a chosen length
        // returns only the seeded slots of that length. A closed link offers nothing, as the service.
        if ($this->linkClosed($bookingTypeRef) !== null) {
            return ['slots' => [], 'opens_at' => null];
        }

        // A wait after a reported event (each host's own, else the policy's): nothing before it is offered.
        $now = $this->now()->getTimestamp();
        $opensAt = null;
        $slots = array_values(array_filter(
            $this->slots[$bookingTypeRef] ?? [],
            function (Slot $s) use ($from, $to, $durationMinutes, $since, $now, &$opensAt) {
                if (! ($s->start >= $from && $s->start < $to && ! $this->taken($s->start->getTimestamp())
                    && ($durationMinutes === null || $s->end->getTimestamp() - $s->start->getTimestamp() === $durationMinutes * 60))) {
                    return false;
                }
                $floor = $this->waitFloor($since, $s->hostIds);
                if ($floor !== null && $floor > $now && $floor > $from->getTimestamp()) {
                    $opensAt = max($opensAt ?? $floor, $floor);
                }

                return $floor === null || $s->start->getTimestamp() >= $floor;
            }
        ));

        return ['slots' => $slots, 'opens_at' => $opensAt === null ? null : (new DateTimeImmutable('@'.$opensAt))];
    }

    /**
     * The first instant booking opens after the reported events, as the service reads the policy's
     * `wait_after`; null when nothing waits. A malformed `since` is refused as the service does.
     */
    private function waitFloor(?array $since, array $hostIds = []): ?int
    {
        $since = CalendarServiceClient::since($since);
        if ($since === null) {
            return null;
        }
        $policy = (array) (($this->schedulingPolicy ?? [])['wait_after'] ?? []);
        // Each host's own wait, else the policy's; the longest among the hosts.
        $waits = $policy;
        foreach (array_keys($since) as $event) {
            $each = array_map(fn (string $h) => array_key_exists($event, $this->hostWaits[$h] ?? []) ? (int) $this->hostWaits[$h][$event] : (int) ($policy[$event] ?? 0), $hostIds);
            if ($each !== []) {
                $waits[$event] = max($each);
            }
        }
        $floor = null;
        foreach ($since as $event => $at) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $event)) {
                throw new CalendarRequestRejected("since: [{$event}] is not an event name (lowercase letters, digits and _).", 'invalid_request', 422);
            }
            // This booker's own wait for the event replaces the hosts' and the policy's.
            $own = null;
            if (is_array($at)) {
                $own = $at['wait'] ?? null;
                if (! is_int($own) || $own < 0 || $own > 60 * 24 * 90) {
                    throw new CalendarRequestRejected("since.{$event} is an ISO 8601 instant, or {at, wait} with a wait in whole minutes from 0 to 129600.", 'invalid_request', 422);
                }
                $at = $at['at'];
            }
            try {
                $ts = (new DateTimeImmutable($at))->getTimestamp();
            } catch (\Throwable) {
                throw new CalendarRequestRejected("since.{$event} must be an ISO 8601 instant.", 'invalid_request', 422);
            }
            $minutes = $own ?? (int) ($waits[$event] ?? 0);
            if ($minutes > 0) {
                $floor = max($floor ?? 0, $ts + $minutes * 60);
            }
        }

        return $floor;
    }

    /** Refuses a start the policy's wait still closes, with the service's sentence. */
    private function assertOpen(DateTimeInterface $start, ?array $since, array $hostIds = []): void
    {
        $floor = $this->waitFloor($since, $hostIds);
        if ($floor !== null && $start->getTimestamp() < $floor) {
            throw new CalendarRequestRejected('That time is not open for booking yet. Times open from '
                .(new DateTimeImmutable('@'.$floor))->format('Y-m-d\TH:i:sP').'.', 'not_open_yet', 409);
        }
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

        // As the service: a deadline for an answer is ahead, no later than the start, and only with approval.
        $respondBy = null;
        if (! empty($details['respond_by'])) {
            $respondBy = $details['respond_by'] instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($details['respond_by'])
                : new DateTimeImmutable((string) $details['respond_by']);
            if (empty($details['approval']) || $respondBy <= $this->now() || $respondBy > DateTimeImmutable::createFromInterface($start)) {
                throw new CalendarRequestRejected('respond_by must be a time in the future, no later than the booking start, for a booking that needs approval.', 'invalid', 422);
            }
        }

        $closed = $this->linkClosed($bookingTypeRef);
        if ($closed !== null) {
            throw new CalendarRequestRejected(match ($closed) {
                'expired' => 'This booking link has expired.',
                'full' => 'This booking link has taken all the bookings it allows.',
                default => 'This booking link is not taking bookings right now.',
            }, 'link_'.$closed, 409);
        }

        $slot = null;
        foreach ($this->slots[$bookingTypeRef] ?? [] as $s) {
            if ($s->start->getTimestamp() === $start->getTimestamp()) {
                $slot = $s;
            }
        }
        $this->assertOpen($start, $details['since'] ?? null, $slot?->hostIds ?? []);
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
            // As the service: a hold keeps its text and the zone it was booked in.
            'title' => $details['title'] ?? null, 'description' => $details['description'] ?? null,
            'location' => $details['location'] ?? null, 'zone' => $details['zone'] ?? null,
            'approval_required' => ! empty($details['approval']),
            'respond_by' => $respondBy ? $this->iso($respondBy) : null,
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
        $needsApproval = (bool) ($this->bookings[$bookingId]['approval_required'] ?? false)
            || (bool) ($this->bookingTypes[$this->bookings[$bookingId]['booking_type']]['approval']['required'] ?? false)
            // As the service: any host who approves their own bookings makes it pending too.
            || array_filter((array) ($this->bookings[$bookingId]['hosts'] ?? []), fn ($h) => (bool) ($this->hostPreferences[$h]['requires_approval'] ?? false)) !== [];

        $this->record($bookingId, 'confirmed', Booking::HELD, $needsApproval ? Booking::PENDING : Booking::CONFIRMED, $actor);

        return $this->move($bookingId, [Booking::HELD], $needsApproval ? Booking::PENDING : Booking::CONFIRMED);
    }

    public function approve(string $bookingId, ?string $actor = null): Booking
    {
        // As the service: approving a booking that is already confirmed changes nothing, and an
        // answer after its deadline is refused.
        $was = $this->find($bookingId)['state'];
        $due = $this->find($bookingId)['respond_by'] ?? null;
        if ($was === Booking::PENDING && $due !== null && new DateTimeImmutable($due) <= $this->now()) {
            throw new CalendarRequestRejected('The time to answer this booking has passed.', 'approval_expired', 409);
        }
        // As the service: the booker's yes to a booking needing its own approval, whose host also
        // approves their bookings, leaves it pending for the host with no deadline.
        $b = $this->find($bookingId);
        if ($was === Booking::PENDING && ($b['approval_required'] ?? false)
            && ((bool) ($this->bookingTypes[$b['booking_type']]['approval']['required'] ?? false)
                || array_filter((array) ($b['hosts'] ?? []), fn ($h) => (bool) ($this->hostPreferences[$h]['requires_approval'] ?? false)) !== [])) {
            $this->record($bookingId, 'booker_approved', Booking::PENDING, Booking::PENDING, $actor);

            return $this->save(['approval_required' => false, 'respond_by' => null] + $b);
        }
        $booking = $this->move($bookingId, [Booking::PENDING, Booking::CONFIRMED], Booking::CONFIRMED);
        if ($was === Booking::PENDING) {
            $this->record($bookingId, 'approved', $was, Booking::CONFIRMED, $actor);
        }

        return $was === Booking::PENDING ? $this->save(['decided_at' => $this->iso($this->now()), 'decided_actor' => $actor] + $booking->raw) : $booking;
    }

    public function decline(string $bookingId, ?string $actor = null, ?string $reason = null): Booking
    {
        $was = $this->find($bookingId)['state'];
        $booking = $this->move($bookingId, [Booking::PENDING, Booking::DECLINED], Booking::DECLINED);
        if ($was === Booking::PENDING) {
            $this->record($bookingId, 'declined', $was, Booking::DECLINED, $actor, $reason);
        }

        return $was === Booking::PENDING ? $this->save(['decided_at' => $this->iso($this->now()), 'decided_actor' => $actor, 'decision_reason' => $reason] + $booking->raw) : $booking;
    }

    public function reschedule(string $bookingId, DateTimeInterface $start, ?string $actor = null, ?string $reason = null, ?DateTimeInterface $end = null, bool $hostOverride = false, ?string $by = null, ?array $since = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        $this->assertOpen($start, $since, (array) ($b['hosts'] ?? []));
        if (! in_array($b['state'], [Booking::CONFIRMED, Booking::PENDING], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot be moved.", 'invalid_state', 409);
        }
        if ($by === 'booker') {
            $this->assertNotice($b, $this->rescheduleNoticeMinutes, 'Rescheduling');
        }
        if ($this->taken($start->getTimestamp(), $bookingId)) {
            throw new SlotUnavailable('That time is no longer available. Please pick another.', 'slot_unavailable', 409);
        }

        $length = $end !== null
            ? $end->getTimestamp() - $start->getTimestamp()
            : (new DateTimeImmutable($b['end_utc']))->getTimestamp() - (new DateTimeImmutable($b['start_utc']))->getTimestamp();
        $old = [$b['start_utc'], $b['end_utc']];
        $b['start_utc'] = $this->iso(DateTimeImmutable::createFromInterface($start));
        $b['end_utc'] = $this->iso((DateTimeImmutable::createFromInterface($start))->modify("+{$length} seconds"));
        $this->record($bookingId, 'rescheduled', $b['state'], $b['state'], $actor, $reason,
            ['old_start_utc' => $old[0], 'old_end_utc' => $old[1], 'new_start_utc' => $b['start_utc'], 'new_end_utc' => $b['end_utc']]);

        return $this->save($b);
    }

    public function propose(string $bookingId, DateTimeInterface $start, string $proposedBy, ?string $actor = null, ?DateTimeInterface $expiresAt = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->offer($bookingId, [$start], $proposedBy, $expiresAt)['proposals'][0];
    }

    public function proposeTimes(string $bookingId, array $starts, string $proposedBy, ?string $actor = null, ?DateTimeInterface $expiresAt = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->offer($bookingId, $starts, $proposedBy, $expiresAt);
    }

    /** As the service: up to 3 distinct times, soonest first; a new offer replaces the open one. */
    private function offer(string $bookingId, array $starts, string $proposedBy, ?DateTimeInterface $expiresAt): array
    {
        $b = $this->find($bookingId);
        $times = [];
        foreach ($starts as $s) {
            $times[DateTimeImmutable::createFromInterface($s)->getTimestamp()] = DateTimeImmutable::createFromInterface($s);
        }
        ksort($times);
        if ($times === [] || count($times) > 3) {
            throw new CalendarRequestRejected('Offer from 1 to 3 different times.', 'invalid_request', 422);
        }
        foreach ($this->proposals as $id => $p) {
            if ($p['booking_id'] === $bookingId && $p['state'] === 'open') {
                $this->proposals[$id]['state'] = 'superseded';
            }
        }
        $length = (new DateTimeImmutable($b['end_utc']))->getTimestamp() - (new DateTimeImmutable($b['start_utc']))->getTimestamp();
        $setId = count($times) > 1 ? sprintf('set-%d', count($this->proposals) + 1) : null;
        $made = [];
        foreach ($times as $ts => $t) {
            $id = 'p-'.(count($this->proposals) + 1);
            $made[] = $this->proposals[$id] = ['id' => $id, 'booking_id' => $bookingId, 'set_id' => $setId,
                'start_utc' => $this->iso($t), 'end_utc' => $this->iso($t->modify('+'.$length.' seconds')), 'proposed_by' => $proposedBy,
                'expires_at' => $this->iso($expiresAt !== null ? DateTimeImmutable::createFromInterface($expiresAt) : $this->now()->modify('+48 hours')), 'state' => 'open'];
        }
        $this->syncOffer($bookingId);

        return ['set_id' => $setId, 'proposals' => $made];
    }

    /** The booking reads its open offer back as the service writes it (`open_proposal(s)`). */
    private function syncOffer(string $bookingId): void
    {
        if (! isset($this->bookings[$bookingId])) {
            return;
        }
        $open = array_values(array_filter($this->proposals, fn ($p) => $p['booking_id'] === $bookingId && $p['state'] === 'open'));
        usort($open, fn ($a, $b) => strcmp($a['start_utc'], $b['start_utc']));
        $this->bookings[$bookingId]['open_proposals'] = $open;
        $this->bookings[$bookingId]['open_proposal'] = $open[0] ?? null;
    }

    public function acceptProposal(string $proposalId, ?string $actor = null): Booking
    {
        $p = $this->proposals[$proposalId] ?? throw new CalendarNotFound('Proposal not found.', null, 404);
        if ($p['state'] !== 'open') {
            throw new CalendarRequestRejected('This proposal is no longer open.', 'proposal_closed', 409);
        }
        $this->proposals[$proposalId]['state'] = 'accepted';
        // The other times of the same offer close.
        foreach ($this->proposals as $id => $other) {
            if ($other['booking_id'] === $p['booking_id'] && $other['state'] === 'open') {
                $this->proposals[$id]['state'] = 'superseded';
            }
        }
        $this->syncOffer($p['booking_id']);

        return $this->reschedule($p['booking_id'], new DateTimeImmutable($p['start_utc']), $actor);
    }

    public function declineProposal(string $proposalId, ?string $actor = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $p = $this->proposals[$proposalId] ?? throw new CalendarNotFound('Proposal not found.', null, 404);
        if ($p['state'] !== 'open') {
            throw new CalendarRequestRejected('This proposal is no longer open.', 'proposal_closed', 409);
        }
        // Declining an offer declines every time in it.
        foreach ($this->proposals as $id => $other) {
            if ($id === $proposalId || (($p['set_id'] ?? null) !== null && ($other['set_id'] ?? null) === $p['set_id'] && $other['state'] === 'open')) {
                $this->proposals[$id]['state'] = 'declined';
            }
        }
        $this->syncOffer($p['booking_id']);

        return $this->proposals[$proposalId];
    }

    public function cancel(string $bookingId, string $by, ?string $actor = null, ?string $reason = null): Booking
    {
        // As the service: the notice binds the booker only, who is offered moving it instead
        // whenever the reschedule notice still allows (owner 2026-10-22).
        if ($by === 'booker' && isset($this->bookings[$bookingId])) {
            $b = $this->bookings[$bookingId];
            $start = strtotime((string) ($b['start_utc'] ?? ''));
            $now = class_exists(\Carbon\Carbon::class) ? \Carbon\Carbon::now()->getTimestamp() : time();
            $mayMove = in_array($b['state'] ?? null, [Booking::CONFIRMED, Booking::PENDING], true)
                && ($this->rescheduleNoticeMinutes <= 0 || ($start !== false && $start - $now >= $this->rescheduleNoticeMinutes * 60));
            $this->assertNotice($b, $this->cancelNoticeMinutes, 'Cancellation', $mayMove ? 'reschedule' : null);
        }
        $from = $this->find($bookingId)['state'];
        $b = $this->move($bookingId, [Booking::HELD, Booking::PENDING, Booking::CONFIRMED], Booking::CANCELLED);
        $this->record($bookingId, 'cancelled', $from, Booking::CANCELLED, $actor, $reason);

        return $this->save(['cancelled_by' => $by, 'cancel_reason' => $reason, 'cancelled_at' => $this->iso($this->now()), 'cancelled_actor' => $actor] + $b->raw);
    }

    public function markAttendance(string $bookingId, int $participantId, string $status, ?string $actor = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        if (isset($this->bookings[$bookingId])) {
            foreach ($this->bookings[$bookingId]['participants'] as $i => $p) {
                if (($p['id'] ?? null) === $participantId) {
                    $this->bookings[$bookingId]['participants'][$i]['attendance'] = $status;

                    return $this->bookings[$bookingId]['participants'][$i];
                }
            }
        }

        return ['id' => $participantId, 'attendance' => $status];
    }

    public function markAttendanceFor(string $bookingId, string $role, string $status, array $who = [], ?string $actor = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if (! in_array($b['state'], [Booking::CONFIRMED, Booking::COMPLETED], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot be given attendance.", 'invalid_state', 409);
        }
        $participants = array_values((array) ($b['participants'] ?? []));
        foreach ($participants as $i => $p) {
            if ($this->isPerson($participants, $i, $role, $who)) {
                $participants[$i]['attendance'] = $status;
                $b['participants'] = $participants;
                $this->save($b);

                return $participants[$i];
            }
        }
        if (empty($who['auth_id']) && empty($who['email'])) {
            throw new CalendarNotFound("This booking has no {$role}; say who they are (auth_id or email).", null, 404);
        }
        $p = ['id' => $this->participantId(), 'auth_id' => $who['auth_id'] ?? null, 'email' => $who['email'] ?? null,
            'name' => $who['name'] ?? null, 'role' => $role, 'attendance' => $status];
        $b['participants'] = [...$participants, $p];
        $this->save($b);

        return $p;
    }

    public function markHostAttendance(string $bookingId, string $hostAuthId, string $status, ?string $actor = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if (! in_array($b['state'], [Booking::CONFIRMED, Booking::COMPLETED], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot be given attendance.", 'invalid_state', 409);
        }
        if (! in_array($hostAuthId, (array) ($b['hosts'] ?? []), true)) {
            throw new CalendarNotFound('That person is not a host of this booking.', null, 404);
        }
        $b['host_attendance'] = array_merge((array) ($b['host_attendance'] ?? []), [$hostAuthId => $status]);

        return $this->save($b);
    }

    public function respond(string $bookingId, string $role, string $response, array $who = [], ?string $reason = null, ?string $actor = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if (! in_array($b['state'], [Booking::PENDING, Booking::CONFIRMED], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot be answered.", 'invalid_state', 409);
        }
        $participants = array_values((array) ($b['participants'] ?? []));
        foreach ($participants as $i => $p) {
            if ($this->isPerson($participants, $i, $role, $who)) {
                $participants[$i]['response'] = $response;
                $participants[$i]['response_reason'] = $reason;
                $b['participants'] = $participants;
                $this->save($b);

                return $participants[$i];
            }
        }
        if (empty($who['auth_id']) && empty($who['email'])) {
            throw new CalendarNotFound("This booking has no {$role}; say who they are (auth_id or email).", null, 404);
        }
        $p = ['id' => $this->participantId(), 'auth_id' => $who['auth_id'] ?? null, 'email' => $who['email'] ?? null,
            'name' => $who['name'] ?? null, 'role' => $role, 'attendance' => null, 'response' => $response, 'response_reason' => $reason];
        $b['participants'] = [...$participants, $p];
        $this->save($b);

        return $p;
    }

    /**
     * Whether participant $i is the one a role call means (as the service): the person $who names
     * when it names one, else (or for the one booker) the role's first.
     */
    private function isPerson(array $participants, int $i, string $role, array $who): bool
    {
        $key = ! empty($who['auth_id']) ? 'auth_id' : (! empty($who['email']) ? 'email' : null);
        if ($key !== null) {
            foreach ($participants as $j => $p) {
                if (($p[$key] ?? null) === $who[$key]) {
                    return $j === $i;
                }
            }
            if ($role !== 'booker') {
                return false;
            }
        }
        foreach ($participants as $j => $p) {
            if (($p['role'] ?? null) === $role) {
                return $j === $i;
            }
        }

        return false;
    }

    public function addParticipant(string $bookingId, array $who, string $role = 'attendee', ?string $actor = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        // As the service: a booking that never recorded its booker may have them recorded once, in any state.
        $recordsBooker = $role === 'booker' && collect((array) ($b['participants'] ?? []))->where('role', 'booker')->isEmpty();
        if (! $recordsBooker && ! in_array($b['state'], [Booking::PENDING, Booking::CONFIRMED], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot be given a participant.", 'invalid_state', 409);
        }
        if ((! $recordsBooker && ! in_array($role, ['attendee', 'guest'], true)) || (empty($who['auth_id']) && empty($who['email']))) {
            throw new CalendarRequestRejected('Say who they are (auth_id or email); role is attendee or guest.', 'invalid_request', 422);
        }
        $participants = array_values((array) ($b['participants'] ?? []));
        foreach ($participants as $p) {
            if ((! empty($who['auth_id']) && ($p['auth_id'] ?? null) === $who['auth_id']) || (empty($who['auth_id']) && ($p['email'] ?? null) === $who['email'])) {
                return $this->booking($bookingId);
            }
        }
        $b['participants'] = [...$participants, ['id' => $this->participantId(), 'auth_id' => $who['auth_id'] ?? null, 'email' => $who['email'] ?? null,
            'name' => $who['name'] ?? null, 'phone' => $who['phone'] ?? null, 'organization' => $who['organization'] ?? null,
            'zone' => $who['zone'] ?? null, 'role' => $role, 'attendance' => null, 'response' => null]];

        return $this->save($b);
    }

    public function removeParticipant(string $bookingId, int $participantId, ?string $actor = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        $participants = array_values((array) ($b['participants'] ?? []));
        foreach ($participants as $i => $p) {
            if (($p['id'] ?? null) === $participantId) {
                if (($p['role'] ?? null) === 'booker') {
                    throw new CalendarRequestRejected('The booker cannot be removed; cancel the booking instead.', 'invalid_request', 422);
                }
                unset($participants[$i]);
                $b['participants'] = array_values($participants);

                return $this->save($b);
            }
        }

        throw new CalendarNotFound('That participant is not on this booking.', null, 404);
    }

    public function updateParticipant(string $bookingId, int $participantId, array $fields, ?string $actor = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        $participants = array_values((array) ($b['participants'] ?? []));
        foreach ($participants as $i => $p) {
            if (($p['id'] ?? null) === $participantId) {
                $participants[$i] = array_merge($p, array_intersect_key($fields, array_flip(['name', 'zone', 'phone', 'organization'])));
                $b['participants'] = $participants;

                return $this->save($b);
            }
        }

        throw new CalendarNotFound('That participant is not on this booking.', null, 404);
    }

    /** @var array<string, array<string, mixed>> note id => note */
    public array $notes = [];

    /** @var array<string, array<string, mixed>> "booking|author" => pad */
    public array $drafts = [];

    public function notes(string $bookingId, ?string $viewerAuthId = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->find($bookingId);

        return array_values(array_filter($this->notes, fn ($n) => $n['booking_id'] === $bookingId
            && ($viewerAuthId === null || $n['visibility'] === 'participants' || $n['author_auth_id'] === $viewerAuthId)));
    }

    public function addNote(string $bookingId, string $authorAuthId, string $content, string $visibility = 'participants', ?string $sourceRef = null, ?array $meta = null, ?DateTimeInterface $createdAt = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->find($bookingId);
        foreach ($this->notes as $n) {
            if ($sourceRef !== null && $n['booking_id'] === $bookingId && $n['source_ref'] === $sourceRef) {
                return $n;
            }
        }
        $id = sprintf('%08x-0000-4000-a000-%012x', count($this->notes) + 1, random_int(0, 0xFFFFFFFFFFFF));
        $at = $this->iso($createdAt === null ? $this->now() : DateTimeImmutable::createFromInterface($createdAt));

        return $this->notes[$id] = ['id' => $id, 'booking_id' => $bookingId, 'author_auth_id' => $authorAuthId, 'content' => $content,
            'visibility' => $visibility, 'source_ref' => $sourceRef, 'meta' => $meta ?? [], 'created_at' => $at, 'updated_at' => $at];
    }

    public function updateNote(string $noteId, array $fields): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $n = $this->notes[$noteId] ?? throw new CalendarNotFound('Note not found.', null, 404);
        foreach (['content', 'visibility'] as $k) {
            if (array_key_exists($k, $fields)) {
                $n[$k] = $fields[$k];
            }
        }
        if (is_array($fields['meta'] ?? null)) {
            $n['meta'] = array_merge((array) $n['meta'], $fields['meta']);
        }
        $n['updated_at'] = $this->iso($this->now());

        return $this->notes[$noteId] = $n;
    }

    public function deleteNote(string $noteId): void
    {
        $this->log(__FUNCTION__, func_get_args());
        if (! isset($this->notes[$noteId])) {
            throw new CalendarNotFound('Note not found.', null, 404);
        }
        unset($this->notes[$noteId]);
    }

    public function eraseBooking(string $bookingId): array
    {
        $this->log(__FUNCTION__, func_get_args());
        if (! isset($this->bookings[$bookingId])) {
            return ['bookings' => 0];
        }
        unset($this->bookings[$bookingId]);
        $notes = 0;
        foreach ($this->notes as $id => $n) {
            if (($n['booking_id'] ?? null) === $bookingId) {
                unset($this->notes[$id]);
                $notes++;
            }
        }
        $this->erased[] = $bookingId;

        return ['notes' => $notes, 'bookings' => 1];
    }

    public function forgetPerson(?string $email, ?string $authId): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->forgotten[] = ['email' => $email, 'auth_id' => $authId];

        return ['participants' => 0, 'notes' => 0, 'pads' => 0, 'actors' => 0];
    }

    public function draft(string $bookingId, string $authorAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->find($bookingId);

        return $this->drafts[$bookingId.'|'.$authorAuthId] ?? ['booking_id' => $bookingId, 'author_auth_id' => $authorAuthId, 'content' => '', 'updated_at' => null];
    }

    public function saveDraft(string $bookingId, string $authorAuthId, string $content): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->find($bookingId);

        return $this->drafts[$bookingId.'|'.$authorAuthId] = ['booking_id' => $bookingId, 'author_auth_id' => $authorAuthId, 'content' => $content, 'updated_at' => $this->iso($this->now())];
    }

    public function discardDraft(string $bookingId, string $authorAuthId): void
    {
        $this->log(__FUNCTION__, func_get_args());
        unset($this->drafts[$bookingId.'|'.$authorAuthId]);
    }

    public function pendingDrafts(string $authorAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $out = [];
        foreach ($this->drafts as $d) {
            if ($d['author_auth_id'] === $authorAuthId && trim($d['content']) !== '' && isset($this->bookings[$d['booking_id']])) {
                $b = $this->bookings[$d['booking_id']];
                $out[] = $d + ['booking' => ['id' => $b['id'], 'product_ref' => $b['product_ref'] ?? null, 'title' => $b['title'] ?? null,
                    'state' => $b['state'], 'start_utc' => $b['start_utc'], 'end_utc' => $b['end_utc']]];
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

        return $out;
    }

    public function complete(string $bookingId, ?string $actor = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if ($b['state'] === Booking::COMPLETED) {
            return Booking::fromArray($b);
        }
        // As the service: the product attests it (a call can run early, in the join window).
        return $this->move($bookingId, [Booking::CONFIRMED], Booking::COMPLETED);
    }

    private int $participantSeq = 0;

    /** Participants carry ids, as the service's do. */
    private function participantId(): int
    {
        return ++$this->participantSeq;
    }

    /** @return list<array<string, mixed>> */
    private function withParticipantIds(array $participants): array
    {
        return array_map(fn ($p) => ['id' => $p['id'] ?? $this->participantId(), 'attendance' => $p['attendance'] ?? null] + (array) $p, array_values($participants));
    }

    public function rebook(string $bookingId, DateTimeInterface $start, ?string $actor = null, ?string $by = null, ?DateTimeInterface $end = null, ?string $productRef = null, ?array $since = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $old = $this->find($bookingId);
        $this->assertOpen($start, $since, (array) ($old['hosts'] ?? []));
        if (isset($old['rebooked_to'], $this->bookings[$old['rebooked_to']]) && $this->bookings[$old['rebooked_to']]['state'] !== Booking::CANCELLED) {
            return Booking::fromArray($this->bookings[$old['rebooked_to']]);
        }
        $outcome = Booking::deriveOutcome($old);
        if (! in_array($outcome, [Booking::OUTCOME_NO_SHOW, Booking::OUTCOME_HOST_NO_SHOW, Booking::OUTCOME_NOBODY_CAME, Booking::OUTCOME_UNRECORDED, Booking::OUTCOME_CANCELLED], true)) {
            throw new CalendarRequestRejected("A booking that is {$outcome} cannot be rebooked.", 'not_rebookable', 409);
        }
        $length = (new DateTimeImmutable($old['end_utc']))->getTimestamp() - (new DateTimeImmutable($old['start_utc']))->getTimestamp();
        $end ??= DateTimeImmutable::createFromInterface($start)->modify("+{$length} seconds");
        $people = array_map(fn (array $p) => array_intersect_key($p, array_flip(['auth_id', 'name', 'email', 'zone', 'role'])), (array) ($old['participants'] ?? []));

        $new = $this->createMeeting((array) $old['hosts'], $start, $end, "rebook:{$bookingId}:".$start->getTimestamp(), [
            'participants' => $people, 'product_ref' => $productRef ?? ($old['product_ref'] ?? null),
            'title' => $old['title'] ?? null, 'description' => $old['description'] ?? null, 'location' => $old['location'] ?? null,
        ]);
        $this->bookings[$new->id]['rebooked_from'] = $bookingId;
        $this->bookings[$bookingId]['rebooked_to'] = $new->id;
        $this->record($bookingId, 'rebooked', $old['state'], $old['state'], $actor, $by);

        return Booking::fromArray($this->bookings[$new->id]);
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
        $this->record($id, 'created', null, empty($details['pending']) ? Booking::CONFIRMED : Booking::PENDING, $details['actor'] ?? null);

        return $this->save([
            'id' => $id, 'state' => empty($details['pending']) ? Booking::CONFIRMED : Booking::PENDING, 'kind' => 'host_created', 'start_utc' => $this->iso(DateTimeImmutable::createFromInterface($start)),
            'end_utc' => $this->iso(DateTimeImmutable::createFromInterface($end)), 'hosts' => $hostAuthIds,
            'participants' => $this->withParticipantIds($details['participants'] ?? []),
            'product_ref' => $details['product_ref'] ?? null, 'title' => $details['title'] ?? null,
            'description' => $details['description'] ?? null, 'location' => $details['location'] ?? null,
            'zone' => $details['zone'] ?? null,
            'idempotency_key' => $idempotencyKey, 'hold_expires_at' => null, 'open_proposal' => null,
        ]);
    }

    public function booking(string $bookingId): Booking
    {
        $this->log(__FUNCTION__, func_get_args());

        return Booking::fromArray($this->find($bookingId));
    }

    public function history(string $bookingId): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        $blank = ['from_state' => null, 'to_state' => null, 'actor' => null, 'reason' => null, 'at' => null,
            'old_start_utc' => null, 'old_end_utc' => null, 'new_start_utc' => null, 'new_end_utc' => null];

        // An imported booking brings its history with it; then everything since, as it happened.
        return array_values(array_map(fn (array $c) => $c + $blank, [...(array) ($b['history'] ?? []), ...($this->histories[$bookingId] ?? [])]));
    }

    /** @param array<string, ?string> $times a move's old and new times */
    private function record(string $id, string $event, ?string $from, ?string $to, ?string $actor = null, ?string $reason = null, array $times = []): void
    {
        $this->histories[$id][] = ['event' => $event, 'from_state' => $from, 'to_state' => $to, 'actor' => $actor,
            'reason' => $reason, 'at' => $this->iso($this->now())] + $times;
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

    public function connectUrl(string $hostAuthId, string $provider, string $returnUrl, ?string $thenUse = null): string
    {
        $this->log(__FUNCTION__, func_get_args());
        if ($thenUse !== null && ! (($thenUse === 'zoom' && $provider === 'zoom') || ($thenUse === 'google_meet' && $provider === 'google'))) {
            throw new CalendarRequestRejected('then_use must be zoom (with provider zoom) or google_meet (with provider google).', 'invalid', 422);
        }

        return "https://calendar.example.test/oauth/{$provider}/start?host=".rawurlencode($hostAuthId).($thenUse !== null ? '&then_use='.$thenUse : '');
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

    public function updateDetails(string $bookingId, ?string $title = null, ?string $description = null, ?string $location = null, ?string $zone = null): Booking
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        foreach (['title' => $title, 'description' => $description, 'location' => $location, 'zone' => $zone] as $k => $v) {
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

    /** @var array<string, list<array>> host => the calendar connections the test says they have */
    public array $connections = [];

    public function connections(string $hostAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->connections[$hostAuthId] ?? [];
    }

    public function updateConnection(int $connectionId, string $hostAuthId, ?array $busyCalendars = null, ?string $writeCalendar = null): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return ['id' => $connectionId];
    }

    /** @var array<string, list<array>> host => the call tools the test says they connected */
    public array $callTools = [];

    public function callTools(string $hostAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->callTools[$hostAuthId] ?? [];
    }

    public function disconnectCallTool(int $callToolId, string $hostAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $this->callTools[$hostAuthId] = array_values(array_filter($this->callTools[$hostAuthId] ?? [], fn ($t) => ($t['id'] ?? null) !== $callToolId));
        // As the service: a preference for a tool that is no longer connected falls away.
        $zoomLeft = array_filter($this->callTools[$hostAuthId], fn ($t) => ($t['tool'] ?? null) === 'zoom' && ($t['status'] ?? 'active') === 'active') !== [];
        if (! $zoomLeft && ($this->hostPreferences[$hostAuthId]['call_tool'] ?? null) === 'zoom') {
            $this->hostPreferences[$hostAuthId]['call_tool'] = null;
        }

        return ['id' => $callToolId, 'status' => 'revoked'];
    }

    /** @var array<string, array> booking id => its call link */
    public array $callLinks = [];

    /** The product's backup call account (set it to simulate an admin having connected one). */
    public ?array $backupAccount = null;

    /** @var array<string, array> booking id => its backup call */
    public array $backupCalls = [];

    public function backupAccount(): ?array
    {
        $this->log(__FUNCTION__, func_get_args());

        return $this->backupAccount;
    }

    public function backupAccountConnectUrl(string $returnUrl): string
    {
        $this->log(__FUNCTION__, func_get_args());

        return 'https://accounts.google.com/o/oauth2/v2/auth?fake=backup&return='.rawurlencode($returnUrl);
    }

    public function disconnectBackupAccount(): array
    {
        $this->log(__FUNCTION__, func_get_args());
        if ($this->backupAccount === null) {
            throw new CalendarRequestRejected('No backup call account is connected.', 'not_found', 404);
        }
        $gone = ['status' => 'revoked'] + $this->backupAccount;
        $this->backupAccount = null;

        return $gone;
    }

    public function makeBackupCall(string $bookingId, ?string $by = null, ?string $reason = null): array
    {
        $this->log(__FUNCTION__, func_get_args());
        if (isset($this->backupCalls[$bookingId])) {
            return ['made_now' => false] + $this->backupCalls[$bookingId];
        }
        $offer = $this->backupOffer();
        if (! $offer['ready']) {
            throw new CalendarRequestRejected((string) $offer['reason'], 'invalid', 422);
        }
        $b = $this->find($bookingId);
        if (! in_array($b['state'], [Booking::CONFIRMED, Booking::PENDING], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot get a backup call.", 'invalid_state', 422);
        }
        $this->backupCalls[$bookingId] = [
            'tool' => 'google_meet', 'url' => "https://meet.google.com/backup-{$bookingId}",
            'made_by' => $by, 'reason' => $reason, 'made_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        // The booking carries it, as the service's does (`backup_call` on every read).
        $this->bookings[$bookingId]['backup_call'] = $this->backupCalls[$bookingId];

        return ['made_now' => true] + $this->backupCalls[$bookingId];
    }

    /** @return array{tool: ?string, ready: bool, reason: ?string} */
    private function backupOffer(): array
    {
        $tool = ($this->schedulingPolicy ?? [])['backup_call_tool'] ?? null;
        if ($tool === null) {
            return ['tool' => null, 'ready' => false, 'reason' => 'No backup call is offered.'];
        }
        if ($this->backupAccount === null) {
            return ['tool' => $tool, 'ready' => false, 'reason' => 'No backup call account is connected.'];
        }
        if (($this->backupAccount['status'] ?? 'active') !== 'active') {
            return ['tool' => $tool, 'ready' => false, 'reason' => 'The backup call account needs to be reconnected.'];
        }

        return ['tool' => $tool, 'ready' => true, 'reason' => null];
    }

    /** @var array<string, array{call_tool: ?string, requires_approval: bool}> host => preferences */
    public array $hostPreferences = [];

    /** @var array<string, array<string, int>> host => their own waits (this product's) */
    public array $hostWaits = [];

    public function hostPreferences(string $hostAuthId): array
    {
        $this->log(__FUNCTION__, func_get_args());

        return ($this->hostPreferences[$hostAuthId] ?? ['call_tool' => null, 'requires_approval' => false, 'booking_mode' => 'native'])
            + $this->waitPreferences($hostAuthId);
    }

    /** The waits part of a host's preferences, as the service answers it. */
    private function waitPreferences(string $hostAuthId): array
    {
        $policy = array_merge(self::SCHEDULING_DEFAULTS, $this->schedulingPolicy ?? []);

        return [
            'wait_after' => $this->hostWaits[$hostAuthId] ?? [],
            'wait_after_default' => (array) $policy['wait_after'],
            'wait_after_limits' => (array) $policy['wait_after_limits'],
            'wait_after_max_minutes' => self::MAX_WAIT_MINUTES,
        ];
    }

    /** The service's technical bound for any wait: 90 days. */
    public const MAX_WAIT_MINUTES = 60 * 24 * 90;

    public function setHostPreferences(string $hostAuthId, array $preferences): array
    {
        $this->log(__FUNCTION__, func_get_args());
        $current = $this->hostPreferences[$hostAuthId] ?? ['call_tool' => null, 'requires_approval' => false, 'booking_mode' => 'native'];
        $mode = $preferences['booking_mode'] ?? $current['booking_mode'] ?? 'native';
        if (! in_array($mode, ['native', 'external'], true)) {
            throw new CalendarRequestRejected('booking_mode must be native or external.', 'invalid', 422);
        }
        $tool = array_key_exists('call_tool', $preferences) ? $preferences['call_tool'] : $current['call_tool'];
        if ($tool === 'zoom' && array_filter($this->callTools[$hostAuthId] ?? [], fn ($t) => ($t['tool'] ?? null) === 'zoom' && ($t['status'] ?? 'active') === 'active') === []) {
            throw new CalendarRequestRejected('Connect your Zoom account first.', 'not_connected', 422);
        }
        if (array_key_exists('wait_after', $preferences)) {
            $this->setOwnWaits($hostAuthId, (array) $preferences['wait_after'], $preferences['wait_limit'] ?? null);
        }

        $this->hostPreferences[$hostAuthId] = [
            'call_tool' => $tool,
            'requires_approval' => (bool) ($preferences['requires_approval'] ?? $current['requires_approval']),
            'booking_mode' => $mode,
        ];

        return $this->hostPreferences[$hostAuthId] + $this->waitPreferences($hostAuthId);
    }

    /** A host's own waits, bounded as the service bounds them (a named limit, else 90 days). */
    private function setOwnWaits(string $hostAuthId, array $waits, ?string $limit): void
    {
        $max = self::MAX_WAIT_MINUTES;
        if ($limit !== null) {
            $limits = (array) array_merge(self::SCHEDULING_DEFAULTS, $this->schedulingPolicy ?? [])['wait_after_limits'];
            if (! array_key_exists($limit, $limits)) {
                throw new CalendarRequestRejected("There is no wait limit called [{$limit}].", 'invalid', 422);
            }
            $max = min($max, (int) $limits[$limit]);
        }
        $mine = $this->hostWaits[$hostAuthId] ?? [];
        foreach ($waits as $event => $minutes) {
            if ($minutes === null) {
                unset($mine[$event]);

                continue;
            }
            if (! is_int($minutes) || $minutes < 0 || $minutes > $max) {
                $h = intdiv($max, 60);
                throw new CalendarRequestRejected('A wait is whole minutes from 0 to '.$max.' ('.($h % 24 === 0 ? ($h / 24).' days' : $h.' hours').').', 'invalid', 422);
            }
            $mine[$event] = $minutes;
        }
        $this->hostWaits[$hostAuthId] = $mine;
    }

    public function callLink(string $bookingId, ?string $tool = null, ?string $hostAuthId = null): ?array
    {
        $this->log(__FUNCTION__, func_get_args());
        $b = $this->find($bookingId);
        if (! in_array($b['state'], [Booking::CONFIRMED, Booking::PENDING], true)) {
            throw new CalendarRequestRejected("A booking that is {$b['state']} cannot get a call link.", 'invalid_state', 422);
        }
        $hosts = (array) ($b['hosts'] ?? []);
        $host = $hostAuthId ?? ($hosts[0] ?? null);
        $tool ??= $this->hostPreferences[$host]['call_tool'] ?? null;
        if ($tool === null) {
            return null;
        }
        $connected = $tool === 'google_meet' || array_filter($this->callTools[$host] ?? [], fn ($t) => ($t['tool'] ?? null) === $tool && ($t['status'] ?? 'active') === 'active') !== [];
        if (! $connected) {
            throw new CalendarRequestRejected('This host has not connected Zoom.', 'not_connected', 422);
        }

        return $this->callLinks[$bookingId] ??= [
            'booking_id' => $bookingId, 'tool' => $tool,
            'url' => $tool === 'zoom' ? "https://zoom.us/j/{$bookingId}" : "https://meet.google.com/{$bookingId}",
        ];
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
        'cancel_notice_minutes' => 1440,
        'reschedule_notice_minutes' => 0,
        'wait_after' => [],
        'wait_after_limits' => [],
        'call_tools_offered' => ['zoom', 'google_meet'],
        'calendar_providers_offered' => ['google', 'microsoft'],
        'backup_call_tool' => null,
        'default_duration_minutes' => 60,
        'duration_choices' => [15, 30, 45, 60, 90],
        'session_lengths' => ['quick_question' => 15, 'standard' => 30, 'extended' => 60, 'business' => 60],
        'seed_links' => [['name' => 'Consultation', 'duration' => 30, 'is_default' => true]],
    ];

    /** What the fake server can connect (the real one leaves out apps with no credentials). */
    public array $configured = ['call_tools' => ['zoom', 'google_meet'], 'calendar_providers' => ['google', 'microsoft']];

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
        $policy = array_merge(self::SCHEDULING_DEFAULTS, $this->schedulingPolicy ?? []);

        return [
            'policy' => $policy,
            'is_default' => $this->schedulingPolicy === null,
            'defaults' => self::SCHEDULING_DEFAULTS,
            'offered' => [
                'call_tools' => array_values(array_intersect((array) $policy['call_tools_offered'], $this->configured['call_tools'])),
                'calendar_providers' => array_values(array_intersect((array) $policy['calendar_providers_offered'], $this->configured['calendar_providers'])),
                'backup_call' => $this->backupOffer(),
            ],
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
