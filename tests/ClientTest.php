<?php

namespace Shirahcan\CalendarClient\Tests;

use DateTimeImmutable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\CalendarClient\Booking;
use Shirahcan\CalendarClient\CalendarServiceClient;
use Shirahcan\CalendarClient\Exceptions\CalendarNotFound;
use Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\Exceptions\HoldExpired;
use Shirahcan\CalendarClient\Exceptions\SlotUnavailable;
use Shirahcan\CalendarClient\FakeCalendarClient;
use Shirahcan\CalendarClient\Slot;
use Shirahcan\CalendarClient\WebhookSignature;

class ClientTest extends TestCase
{
    private array $history = [];

    private function client(Response|\Throwable ...$responses): CalendarServiceClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new CalendarServiceClient('http://127.0.0.1:8010', 'test-key', 10, new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8010/']));
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function bookingData(array $over = []): array
    {
        return array_merge(['id' => 'b-1', 'state' => 'held', 'start_utc' => '2026-10-12T13:00:00Z', 'end_utc' => '2026-10-12T14:00:00Z',
            'hold_expires_at' => '2026-10-01T12:15:00Z', 'hosts' => ['auth-1'], 'participants' => [], 'product_ref' => 'case:9', 'booking_type' => 'consult'], $over);
    }

    public function test_slots_are_typed_and_the_key_is_a_bearer_header(): void
    {
        $slots = $this->client($this->json(200, ['success' => true, 'data' => ['slots' => [
            ['start_utc' => '2026-10-12T13:00:00Z', 'end_utc' => '2026-10-12T14:00:00Z', 'host_ids' => ['auth-1']],
        ], 'stale_external' => false]]))->slots('consult', new DateTimeImmutable('2026-10-12T00:00Z'), new DateTimeImmutable('2026-10-13T00:00Z'));

        $this->assertInstanceOf(Slot::class, $slots[0]);
        $this->assertSame(['auth-1'], $slots[0]->hostIds);

        $request = $this->history[0]['request'];
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('/api/v1/booking-types/consult/slots', $request->getUri()->getPath());
        $this->assertStringNotContainsString('test-key', (string) $request->getUri(), 'never a key in a URL');
    }

    public function test_hold_sends_the_idempotency_key_and_returns_a_booking(): void
    {
        $booking = $this->client($this->json(201, ['data' => $this->bookingData()]))
            ->hold('consult', new DateTimeImmutable('2026-10-12T13:00:00Z'), 'k-1', ['product_ref' => 'case:9']);

        $this->assertSame('b-1', $booking->id);
        $this->assertTrue($booking->isLive());
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('k-1', $body['idempotency_key']);
        $this->assertSame('case:9', $body['product_ref']);
    }

    public function test_service_error_codes_become_typed_exceptions(): void
    {
        $cases = [
            [$this->json(409, ['success' => false, 'message' => 'gone', 'errors' => ['code' => 'slot_unavailable']]), SlotUnavailable::class],
            [$this->json(410, ['success' => false, 'message' => 'late', 'errors' => ['code' => 'hold_expired']]), HoldExpired::class],
            [$this->json(404, ['success' => false, 'message' => 'Booking not found.']), CalendarNotFound::class],
            [$this->json(409, ['success' => false, 'message' => 'no', 'errors' => ['code' => 'invalid_state']]), CalendarRequestRejected::class],
            [$this->json(503, ['success' => false, 'message' => 'down']), CalendarServiceUnavailable::class],
        ];

        foreach ($cases as [$response, $class]) {
            try {
                $this->client($response)->confirm('b-1');
                $this->fail("expected {$class}");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e);
            }
        }
    }

    public function test_validation_errors_are_all_kept(): void
    {
        try {
            $this->client($this->json(422, ['success' => false, 'message' => 'invalid', 'errors' => ['a is bad', 'b is bad']]))->upsertSchedule('s', 'auth-1', []);
            $this->fail('expected a rejection');
        } catch (CalendarRequestRejected $e) {
            $this->assertSame(['a is bad', 'b is bad'], $e->errors);
        }
    }

    /** ⚠ D13: unreachable means fail closed with a typed outage, never a silent fallback. */
    public function test_an_unreachable_service_is_an_outage(): void
    {
        $this->expectException(CalendarServiceUnavailable::class);

        $this->client(new ConnectException('refused', new Request('GET', 'x')))->booking('b-1');
    }

    public function test_webhook_signature_round_trip_and_replay_window(): void
    {
        $secret = base64_encode(random_bytes(32));
        $body = '{"event":"booking.confirmed","event_id":"e-1"}';
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'.'.$body, base64_decode($secret), true));

        $this->assertTrue(WebhookSignature::verify($body, $sig, $ts, $secret));
        $this->assertFalse(WebhookSignature::verify($body.' ', $sig, $ts, $secret));
        $this->assertFalse(WebhookSignature::verify($body, $sig, (string) (time() - 90000), $secret));
    }

    public function test_the_fake_keeps_the_services_contract(): void
    {
        $nine = new DateTimeImmutable('2026-10-12T13:00:00Z');
        $fake = (new FakeCalendarClient())
            ->travelTo(new DateTimeImmutable('2026-10-01T12:00:00Z'))
            ->withSlots('consult', [new Slot($nine, $nine->modify('+1 hour'), ['auth-1'])]);

        $b = $fake->hold('consult', $nine, 'k1');
        $this->assertSame($b->id, $fake->hold('consult', $nine, 'k1')->id, 'idempotent');
        $this->assertSame([], $fake->slots('consult', $nine->modify('-1 day'), $nine->modify('+1 day')), 'held slot no longer offered');

        try {
            $fake->hold('consult', $nine, 'k2');
            $this->fail('a held slot cannot be held again');
        } catch (SlotUnavailable) {
        }

        $fake->travelTo(new DateTimeImmutable('2026-10-01T13:00:00Z'));
        $this->expectException(HoldExpired::class);
        $fake->confirm($b->id);
    }

    public function test_the_fake_can_simulate_an_outage(): void
    {
        $fake = (new FakeCalendarClient())->failNext(new CalendarServiceUnavailable('down', 'unreachable'));

        $this->expectException(CalendarServiceUnavailable::class);
        $fake->slots('consult', new DateTimeImmutable(), new DateTimeImmutable('+1 day'));
    }

    public function test_booking_value_object_reads_the_service_shape(): void
    {
        $b = Booking::fromArray($this->bookingData(['state' => 'cancelled', 'hold_expires_at' => null]));

        $this->assertFalse($b->isLive());
        $this->assertNull($b->holdExpiresAt);
        $this->assertSame('case:9', $b->productRef);
    }

    public function test_mirror_and_adopt_hit_their_routes(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => ['kind' => 'mirrored', 'state' => 'confirmed']]),
            $this->json(200, ['success' => true, 'data' => null]),
            $this->json(200, ['success' => true, 'data' => ['adopted' => 1, 'revived' => 0, 'kept' => 0, 'skipped' => 0, 'rows' => [['index' => 0, 'outcome' => 'created']]]]),
        );

        $this->assertSame('mirrored', $client->mirror('meeting:7', ['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'))['kind']);
        $client->removeMirror('meeting:7');
        $this->assertSame(1, $client->adoptConnections([['host_auth_id' => 'auth-1', 'provider' => 'google', 'account_email' => 'a@b.c', 'refresh_token' => 'rt']])['adopted']);

        $this->assertSame('PUT', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/mirror/meeting%3A7', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['hosts' => ['auth-1'], 'start' => '2026-10-12T14:00:00+00:00', 'end' => '2026-10-12T15:00:00+00:00'], json_decode((string) $this->history[0]['request']->getBody(), true));
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
        $this->assertSame('/api/v1/connections/adopt', $this->history[2]['request']->getUri()->getPath());
    }

    public function test_retiring_a_booking_type_deletes_it_on_the_service_and_unknown_is_a_no_op(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => ['ref' => 'interview:7:60']]),
            $this->json(404, ['success' => false, 'message' => 'Booking type not found.']),
        );

        $client->retireBookingType('interview:7:60');
        $client->retireBookingType('gone');

        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/booking-types/interview%3A7%3A60', $this->history[0]['request']->getUri()->getPath());

        $fake = new \Shirahcan\CalendarClient\FakeCalendarClient();
        $fake->upsertBookingType('t', ['duration' => 30]);
        $fake->retireBookingType('t');
        $this->assertArrayHasKey('t', $fake->retiredTypes);
        $fake->upsertBookingType('t', ['duration' => 30]);
        $this->assertSame([], $fake->retiredTypes, 'sending it again offers it again');
    }

    public function test_call_tools_and_call_links_hit_their_routes(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => [['id' => 4, 'host' => 'auth-1', 'tool' => 'zoom', 'account_email' => 'a@b.c', 'status' => 'active', 'last_error' => null]]]),
            $this->json(200, ['success' => true, 'data' => ['booking_id' => 'b-1', 'tool' => 'zoom', 'url' => 'https://zoom.us/j/1']]),
            $this->json(200, ['success' => true, 'data' => ['id' => 4, 'status' => 'revoked']]),
        );

        $this->assertSame('zoom', $client->callTools('auth-1')[0]['tool']);
        $this->assertSame('https://zoom.us/j/1', $client->callLink('b-1', 'zoom')['url']);
        $client->disconnectCallTool(4, 'auth-1');

        $this->assertSame('/api/v1/hosts/auth-1/call-tools', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('/api/v1/bookings/b-1/call-link', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame(['tool' => 'zoom'], json_decode((string) $this->history[1]['request']->getBody(), true));
        $this->assertSame('DELETE', $this->history[2]['request']->getMethod());
        $this->assertSame('/api/v1/call-tools/4', $this->history[2]['request']->getUri()->getPath());
    }

    public function test_the_fake_keeps_host_preferences_and_uses_them_for_a_call_link(): void
    {
        $fake = new FakeCalendarClient();
        $booking = $fake->createMeeting(['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'), 'k-1');

        $this->assertNull($fake->callLink($booking->id), 'no preference: the product uses its own room');
        $fake->callTools['auth-1'] = [['id' => 1, 'tool' => 'zoom', 'status' => 'active']];
        $this->assertSame('zoom', $fake->setHostPreferences('auth-1', ['call_tool' => 'zoom'])['call_tool']);
        $this->assertSame('zoom', $fake->callLink($booking->id)['tool']);
        $this->assertSame([
            'call_tools' => ['zoom', 'google_meet'], 'calendar_providers' => ['google', 'microsoft'],
            'backup_call' => ['tool' => null, 'ready' => false, 'reason' => 'No backup call is offered.'],
        ], $fake->schedulingPolicy()['offered']);

        $fake->disconnectCallTool(1, 'auth-1');
        $this->assertNull($fake->hostPreferences('auth-1')['call_tool'], 'the preference falls away with the tool');
    }

    public function test_the_fake_refuses_a_booker_inside_the_notice_as_the_service_does(): void
    {
        $fake = new FakeCalendarClient();
        $soon = new DateTimeImmutable('+2 hours');
        $booking = $fake->createMeeting(['auth-1'], $soon, $soon->modify('+30 minutes'), 'k-n');
        $fake->cancelNoticeMinutes = $fake->rescheduleNoticeMinutes = 1440;

        try {
            $fake->reschedule($booking->id, $soon->modify('+1 day'), by: 'booker');
            $this->fail('a booker inside the notice must be refused');
        } catch (\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected $e) {
            $this->assertSame('notice_window', $e->errorCode);
            $this->assertSame('Rescheduling must be made at least 24 hours before the meeting.', $e->getMessage());
        }

        // The host moves their own call freely; the product's cancel is never refused.
        $fake->reschedule($booking->id, $soon->modify('+1 hour'), by: 'host');
        $this->assertSame('cancelled', $fake->cancel($booking->id, 'product')->state);
    }

    public function test_the_fake_imports_a_past_meeting_once_and_searches_by_who_and_when(): void
    {
        $fake = new FakeCalendarClient();
        $meeting = ['idempotency_key' => 'meeting:1', 'product_ref' => 'meeting:1', 'hosts' => ['auth-1'], 'state' => 'completed',
            'start' => '2025-03-03T14:00:00Z', 'end' => '2025-03-03T15:00:00Z', 'participants' => [['auth_id' => 'client-9', 'attendance' => 'attended']]];

        $a = $fake->importBooking($meeting);
        $this->assertSame($a->id, $fake->importBooking($meeting)->id, 'idempotent');
        $this->assertSame(1, $fake->searchBookings(['participants' => ['client-9']])['total']);
        $this->assertSame(0, $fake->searchBookings(['hosts' => ['auth-1'], 'from' => '2026-01-01T00:00:00Z'])['total']);
        $this->assertSame('completed', $fake->searchBookings(['product_refs' => ['meeting:1']])['data'][0]->state);
    }

    public function test_the_fake_reads_several_bookings_at_once(): void
    {
        $fake = new FakeCalendarClient();
        $b = $fake->createMeeting(['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'), 'k-1');

        $this->assertSame([$b->id], array_keys($fake->bookings([$b->id, 'missing'])));
    }

    public function test_the_fake_offers_several_times_like_the_service(): void
    {
        $fake = (new FakeCalendarClient())->travelTo(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        $b = $fake->createMeeting(['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'), 'k-o');

        $offer = $fake->proposeTimes($b->id, [new DateTimeImmutable('2026-10-13T16:00:00Z'), new DateTimeImmutable('2026-10-13T15:00:00Z')], 'host');
        $this->assertNotNull($offer['set_id']);
        $this->assertSame(['2026-10-13T15:00:00Z', '2026-10-13T16:00:00Z'], array_column($offer['proposals'], 'start_utc'));
        $this->assertSame('2026-10-13T17:00:00Z', $offer['proposals'][1]['end_utc'], 'each time keeps the length');
        $this->assertCount(2, $fake->booking($b->id)->raw['open_proposals']);

        $moved = $fake->acceptProposal($offer['proposals'][1]['id']);
        $this->assertSame('2026-10-13T16:00:00Z', $moved->raw['start_utc']);
        $this->assertSame('superseded', $fake->proposals[$offer['proposals'][0]['id']]['state']);
        $this->assertSame([], $fake->booking($b->id)->raw['open_proposals']);

        $again = $fake->proposeTimes($b->id, [new DateTimeImmutable('2026-10-14T15:00:00Z'), new DateTimeImmutable('2026-10-14T16:00:00Z')], 'host');
        $fake->declineProposal($again['proposals'][0]['id']);
        $this->assertSame(['declined', 'declined'], [$fake->proposals[$again['proposals'][0]['id']]['state'], $fake->proposals[$again['proposals'][1]['id']]['state']]);
    }

    public function test_the_fake_manages_links_like_the_service(): void
    {
        $fake = new FakeCalendarClient();
        $a = $fake->createLink('auth-1', ['name' => 'Initial Consultation', 'duration' => 30, 'days_ahead' => 14])['ref'];
        $b = $fake->createLink('auth-1', ['name' => 'Extended', 'duration' => 60])['ref'];

        $this->assertSame([true, false], [$fake->links[$a]['is_default'], $fake->links[$b]['is_default']]);
        $this->assertSame('initial-consultation', $fake->links[$a]['slug']);
        $this->assertSame(7, $fake->updateLink($a, ['days_ahead' => 7])['days_ahead']);
        $fake->setDefaultLink($b);
        $this->assertSame($b, $fake->links('auth-1')[0]['ref']);

        try {
            $fake->createLink('auth-1', ['name' => '', 'duration' => 7]);
            $this->fail('refused');
        } catch (\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected $e) {
            $this->assertCount(2, $e->errors);
        }

        $this->assertCount(2, $fake->seedLinks('auth-1'), 'a host with links keeps them');
        $this->assertSame(['Consultation'], array_column($fake->seedLinks('auth-2'), 'name'));

        $this->assertSame([1, 0], [count($fake->linksFor(['auth-1', 'auth-9'])['auth-1']) > 0 ? 1 : 0, count($fake->linksFor(['auth-1', 'auth-9'])['auth-9'])]);

        $fake->deleteLink($b);
        $this->assertTrue($fake->links[$a]['is_default'], 'the default passes on');
    }

    public function test_the_fake_closes_an_expired_or_full_link_like_the_service(): void
    {
        $nine = new DateTimeImmutable('2026-10-12T09:00:00Z');
        $fake = (new FakeCalendarClient())->travelTo(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        $ref = $fake->createLink('auth-1', ['name' => 'Once', 'duration' => 30, 'max_bookings' => 1, 'expires_at' => '2026-10-20T00:00:00Z'])['ref'];
        $fake->withSlots($ref, [new Slot($nine, $nine->modify('+30 minutes'), ['auth-1']), new Slot($nine->modify('+1 hour'), $nine->modify('+90 minutes'), ['auth-1'])]);

        $this->assertSame([0, null, '2026-10-20T00:00:00Z'], [$fake->links('auth-1')[0]['bookings_used'], $fake->links('auth-1')[0]['closed_reason'], $fake->links('auth-1')[0]['expires_at']]);
        $fake->hold($ref, $nine, 'k1');
        $this->assertSame([1, 'full'], [$fake->links('auth-1')[0]['bookings_used'], $fake->links('auth-1')[0]['closed_reason']]);
        $this->assertSame([], $fake->slots($ref, $nine->modify('-1 day'), $nine->modify('+1 day')));
        try {
            $fake->hold($ref, $nine->modify('+1 hour'), 'k2');
            $this->fail('refused');
        } catch (\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected $e) {
            $this->assertSame(['link_full', 409], [$e->errorCode, $e->status]);
        }

        $fake->updateLink($ref, ['max_bookings' => null]);
        $fake->travelTo(new DateTimeImmutable('2026-10-21T00:00:00Z'));
        $this->assertSame('expired', $fake->links('auth-1')[0]['closed_reason']);

        try {
            $fake->updateLink($ref, ['expires_at' => '2026-10-01T00:00:00Z', 'max_bookings' => 0]);
            $this->fail('refused');
        } catch (\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected $e) {
            $this->assertCount(2, $e->errors);
        }
    }

    public function test_the_fake_reads_booking_types_back_in_the_services_shape(): void
    {
        $fake = new FakeCalendarClient();
        $fake->upsertBookingType('link:1', ['duration' => 30, 'daily_cap' => 3, 'horizon' => 'P14D', 'hosts' => ['mode' => 'single', 'members' => []]]);

        $types = $fake->bookingTypes(['link:1', 'missing']);

        $this->assertSame(['link:1'], array_keys($types));
        $this->assertSame(['duration' => 30, 'daily_cap' => 3, 'horizon' => 'P14D'], $types['link:1']['rules']);
    }

    public function test_a_host_who_approves_their_own_bookings_gets_them_pending_in_the_fake(): void
    {
        $nine = new DateTimeImmutable('2026-10-12T13:00:00Z');
        $fake = (new FakeCalendarClient())->withSlots('consult', [new Slot($nine, $nine->modify('+1 hour'), ['auth-1'])]);
        $fake->setHostPreferences('auth-1', ['requires_approval' => true]);

        $this->assertSame(\Shirahcan\CalendarClient\Booking::PENDING, $fake->confirm($fake->hold('consult', $nine, 'k1')->id)->state);
    }

    public function test_how_a_host_takes_bookings_is_a_preference_native_by_default(): void
    {
        $fake = new FakeCalendarClient();
        $this->assertSame('native', $fake->hostPreferences('auth-1')['booking_mode']);
        $this->assertSame('external', $fake->setHostPreferences('auth-1', ['booking_mode' => 'external'])['booking_mode']);
        $this->assertSame('external', $fake->setHostPreferences('auth-1', ['requires_approval' => true])['booking_mode'], 'other keys leave it alone');

        $this->expectException(\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected::class);
        $fake->setHostPreferences('auth-1', ['booking_mode' => 'calendly']);
    }

    public function test_the_fake_makes_a_call_link_once_and_only_for_a_connected_tool(): void
    {
        $fake = new FakeCalendarClient();
        $booking = $fake->createMeeting(['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'), 'k-1');

        try {
            $fake->callLink($booking->id, 'zoom');
            $this->fail('a host without Zoom must be refused');
        } catch (\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected) {
        }

        $fake->callTools['auth-1'] = [['id' => 1, 'tool' => 'zoom', 'status' => 'active']];
        $first = $fake->callLink($booking->id, 'zoom');
        $this->assertSame($first, $fake->callLink($booking->id, 'zoom'));
        $this->assertSame('google_meet', $fake->callLink((string) $fake->createMeeting(['auth-1'], new DateTimeImmutable('2026-10-13T14:00:00Z'), new DateTimeImmutable('2026-10-13T15:00:00Z'), 'k-2')->id, 'google_meet')['tool']);
    }

    public function test_connect_and_use_sends_then_use_and_the_fake_refuses_a_mismatch(): void
    {
        $client = $this->client($this->json(200, ['success' => true, 'data' => ['url' => 'https://zoom.us/oauth/authorize?x=1', 'expires_at' => '2026-10-13T00:10:00Z']]));
        $client->connectUrl('auth-1', 'zoom', 'https://portify.shirah.co/consultant/calendar', 'zoom');
        $this->assertSame(['host' => 'auth-1', 'provider' => 'zoom', 'return_url' => 'https://portify.shirah.co/consultant/calendar', 'then_use' => 'zoom'], json_decode((string) $this->history[0]['request']->getBody(), true));

        $fake = new FakeCalendarClient();
        $this->assertStringContainsString('then_use=google_meet', $fake->connectUrl('auth-1', 'google', 'https://x.test', 'google_meet'));
        $this->expectException(\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected::class);
        $fake->connectUrl('auth-1', 'google', 'https://x.test', 'zoom');
    }

    public function test_the_backup_account_and_backup_call_hit_their_routes(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => null]),
            $this->json(200, ['success' => true, 'data' => ['url' => 'https://accounts.google.com/x', 'expires_at' => '2026-10-12T00:10:00Z']]),
            $this->json(201, ['success' => true, 'data' => ['tool' => 'google_meet', 'url' => 'https://meet.google.com/a', 'made_by' => 'u-1', 'reason' => 'repair_failed', 'made_at' => '2026-10-12T00:00:00Z', 'made_now' => true]]),
            $this->json(200, ['success' => true, 'data' => ['id' => 9, 'status' => 'revoked']]),
        );

        $this->assertNull($client->backupAccount());
        $this->assertSame('https://accounts.google.com/x', $client->backupAccountConnectUrl('https://portify.shirah.co/admin/ai/scheduling'));
        $this->assertTrue($client->makeBackupCall('b-1', 'u-1', 'repair_failed')['made_now']);
        $client->disconnectBackupAccount();

        $this->assertSame('/api/v1/backup-account', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('/api/v1/backup-account/connect-url', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame(['provider' => 'google', 'return_url' => 'https://portify.shirah.co/admin/ai/scheduling'], json_decode((string) $this->history[1]['request']->getBody(), true));
        $this->assertSame('/api/v1/bookings/b-1/backup-call', $this->history[2]['request']->getUri()->getPath());
        $this->assertSame(['by' => 'u-1', 'reason' => 'repair_failed'], json_decode((string) $this->history[2]['request']->getBody(), true));
        $this->assertSame('DELETE', $this->history[3]['request']->getMethod());
    }

    public function test_the_fake_makes_one_backup_call_once_it_is_offered_and_an_account_is_connected(): void
    {
        $fake = new FakeCalendarClient();
        $booking = $fake->createMeeting(['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'), 'k-1');

        foreach ([fn () => null, fn () => $fake->setSchedulingPolicy(['backup_call_tool' => 'google_meet'])] as $step) {
            $step();
            try {
                $fake->makeBackupCall($booking->id);
                $this->fail('a backup call needs the offer and an account');
            } catch (\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected) {
            }
        }

        $fake->backupAccount = ['id' => 1, 'provider' => 'google', 'purpose' => 'backup_calls', 'account_email' => 'calls@shirah.co', 'status' => 'active', 'last_error' => null];
        $this->assertTrue($fake->schedulingPolicy()['offered']['backup_call']['ready']);
        $first = $fake->makeBackupCall($booking->id, 'client-1', 'repair_failed');
        $this->assertTrue($first['made_now']);
        $again = $fake->makeBackupCall($booking->id, 'auth-1');
        $this->assertFalse($again['made_now']);
        $this->assertSame($first['url'], $again['url']);
        $this->assertSame($first['url'], $fake->booking($booking->id)->raw['backup_call']['url'], 'the booking carries it');
        $this->assertSame('revoked', $fake->disconnectBackupAccount()['status']);
        $this->assertNull($fake->backupAccount());
    }

    public function test_the_fake_keeps_mirrors_and_never_logs_tokens(): void
    {
        $fake = new FakeCalendarClient();
        $fake->mirror('meeting:7', ['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'));
        $this->assertArrayHasKey('meeting:7', $fake->mirrors);
        $fake->removeMirror('meeting:7');
        $this->assertSame([], $fake->mirrors);

        $fake->adoptConnections([['refresh_token' => 'rt-SECRET']]);
        $this->assertStringNotContainsString('SECRET', json_encode($fake->calls));
        $this->assertCount(1, $fake->adopted);
    }

    public function test_cutover_calls_send_what_the_service_expects(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => $this->bookingData(['end_utc' => '2026-10-13T00:30:00Z'])]),
            $this->json(200, ['success' => true, 'data' => $this->bookingData(['title' => 'Consultation'])]),
            $this->json(200, ['success' => true, 'data' => $this->bookingData(['kind' => 'host_created'])]),
        );

        $client->reschedule('b1', new DateTimeImmutable('2026-10-13T00:00:00Z'), null, null, new DateTimeImmutable('2026-10-13T00:30:00Z'), true);
        $client->updateDetails('b1', 'Consultation', null, 'https://x.test/m/1');
        $client->promoteMirror('meeting:7', [['provider' => 'google', 'account_email' => 'a@b.c', 'event_id' => 'e1']]);

        $body = fn (int $i) => json_decode((string) $this->history[$i]['request']->getBody(), true);
        $this->assertSame(['start' => '2026-10-13T00:00:00+00:00', 'end' => '2026-10-13T00:30:00+00:00', 'host_override' => true], $body(0));
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
        $this->assertSame(['title' => 'Consultation', 'location' => 'https://x.test/m/1'], $body(1));
        $this->assertSame('/api/v1/mirror/meeting%3A7/promote', $this->history[2]['request']->getUri()->getPath());
    }

    public function test_write_to_sends_the_hosts_or_null_and_the_fake_refuses_a_stranger(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => $this->bookingData()]),
            $this->json(200, ['success' => true, 'data' => $this->bookingData()]),
        );
        $client->writeTo('b1', ['owner']);
        $client->writeTo('b1', null);
        $body = fn (int $i) => json_decode((string) $this->history[$i]['request']->getBody(), true);
        $this->assertSame(['write_hosts' => ['owner']], $body(0));
        $this->assertSame(['write_hosts' => null], $body(1));

        $fake = new \Shirahcan\CalendarClient\FakeCalendarClient();
        $b = $fake->createMeeting(['owner', 'guest'], new DateTimeImmutable('2026-10-15T13:00:00Z'), new DateTimeImmutable('2026-10-15T14:00:00Z'), 'k');
        $fake->writeTo($b->id, ['owner']);
        $this->assertSame(['owner'], $fake->bookings[$b->id]['write_hosts']);
        $this->expectException(\Shirahcan\CalendarClient\Exceptions\CalendarRequestRejected::class);
        $fake->writeTo($b->id, ['stranger']);
    }

    public function test_set_hosts_sends_the_list_and_the_fake_checks_the_people_joining(): void
    {
        $client = $this->client($this->json(200, ['success' => true, 'data' => $this->bookingData()]));
        $client->setHosts('b1', ['emp', 'admin'], false);
        $this->assertSame('PUT', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/bookings/b1/hosts', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['hosts' => ['emp', 'admin'], 'check_busy' => false], json_decode((string) $this->history[0]['request']->getBody(), true));

        $fake = new \Shirahcan\CalendarClient\FakeCalendarClient();
        $b = $fake->createMeeting(['emp'], new DateTimeImmutable('2026-10-15T13:00:00Z'), new DateTimeImmutable('2026-10-15T14:00:00Z'), 'k1');
        $fake->createMeeting(['admin'], new DateTimeImmutable('2026-10-15T13:30:00Z'), new DateTimeImmutable('2026-10-15T14:30:00Z'), 'k2');
        $this->assertSame(['emp', 'admin'], $fake->setHosts($b->id, ['emp', 'admin'], false)->hosts);
        $fake->setHosts($b->id, ['emp']);
        $this->expectException(\Shirahcan\CalendarClient\Exceptions\SlotUnavailable::class);
        $fake->setHosts($b->id, ['emp', 'admin']);
    }

    public function test_the_fake_meeting_honours_the_idempotency_key_and_refuses_any_overlap_for_the_same_host(): void
    {
        $fake = new \Shirahcan\CalendarClient\FakeCalendarClient();
        $a = $fake->createMeeting(['emp'], new DateTimeImmutable('2026-10-15T13:00:00Z'), new DateTimeImmutable('2026-10-15T14:00:00Z'), 'k1');
        $this->assertSame($a->id, $fake->createMeeting(['emp'], new DateTimeImmutable('2026-10-15T13:00:00Z'), new DateTimeImmutable('2026-10-15T14:00:00Z'), 'k1')->id);

        // A different host at the very same moment is fine; the same host 15 minutes later is not.
        $fake->createMeeting(['other'], new DateTimeImmutable('2026-10-15T13:00:00Z'), new DateTimeImmutable('2026-10-15T14:00:00Z'), 'k2');
        $this->expectException(\Shirahcan\CalendarClient\Exceptions\SlotUnavailable::class);
        $fake->createMeeting(['emp'], new DateTimeImmutable('2026-10-15T13:15:00Z'), new DateTimeImmutable('2026-10-15T13:45:00Z'), 'k3');
    }

    public function test_slots_send_a_chosen_length_only_when_given(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => ['slots' => []]]),
            $this->json(200, ['success' => true, 'data' => ['slots' => []]]),
        );
        $client->slots('call-1', new DateTimeImmutable('2026-10-12T00:00:00Z'), new DateTimeImmutable('2026-10-13T00:00:00Z'), 60);
        $client->slots('call-1', new DateTimeImmutable('2026-10-12T00:00:00Z'), new DateTimeImmutable('2026-10-13T00:00:00Z'));

        parse_str($this->history[0]['request']->getUri()->getQuery(), $q0);
        parse_str($this->history[1]['request']->getUri()->getQuery(), $q1);
        $this->assertSame('60', $q0['duration']);
        $this->assertArrayNotHasKey('duration', $q1);
    }

    public function test_the_fake_promotes_a_mirror_into_a_booking(): void
    {
        $fake = new FakeCalendarClient();
        $fake->mirror('meeting:7', ['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'));

        $b = $fake->promoteMirror('meeting:7');

        $this->assertSame('host_created', $b->raw['kind']);
        $this->assertSame([], $fake->mirrors);
        $this->assertSame('2026-10-12T15:30:00Z', $fake->reschedule($b->id, new DateTimeImmutable('2026-10-12T15:00:00Z'), null, null, new DateTimeImmutable('2026-10-12T15:30:00Z'), true)->end->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_holiday_review_calls_hit_their_routes(): void
    {
        $client = $this->client(
            $this->json(200, ['data' => [['date' => '2027-04-13', 'status' => 'proposed']]]),
            $this->json(200, ['data' => ['status' => 'proposed']]),
            $this->json(200, ['data' => ['status' => 'confirmed']]),
        );

        $client->holidays('NG', 2027, ['proposed']);
        $client->proposeHoliday('NG', '2027-04-13', 'Eid al-Fitr', true, [['url' => 'https://x.test']], 'porter');
        $client->confirmHoliday('NG', '2027-04-13', 'admin-1');

        $this->assertSame('year=2027&include=proposed', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame('/api/v1/holidays/NG/2027-04-13/propose', $this->history[1]['request']->getUri()->getPath());
        $this->assertTrue(json_decode((string) $this->history[1]['request']->getBody(), true)['estimated']);
        $this->assertSame(['by' => 'admin-1'], json_decode((string) $this->history[2]['request']->getBody(), true));
    }

    public function test_the_reminder_policy_is_read_and_set_on_the_service(): void
    {
        $client = $this->client(
            $this->json(200, ['data' => ['offsets_minutes' => [1440, 60, 30], 'is_default' => true]]),
            $this->json(200, ['data' => ['offsets_minutes' => [120, 15], 'is_default' => false]]),
        );

        $this->assertSame([1440, 60, 30], $client->reminderPolicy()['offsets_minutes']);
        $this->assertSame([120, 15], $client->setReminderPolicy([120, 15], 'admin-1')['offsets_minutes']);
        $this->assertSame('PUT', $this->history[1]['request']->getMethod());
        $this->assertSame('/api/v1/reminders/policy', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame(['offsets_minutes' => [120, 15], 'updated_by' => 'admin-1'], json_decode((string) $this->history[1]['request']->getBody(), true));

        $fake = new FakeCalendarClient();
        $this->assertTrue($fake->reminderPolicy()['is_default']);
        $this->assertSame([60, 10], $fake->setReminderPolicy([10, 60, 60, 0])['offsets_minutes']);
        $this->assertTrue($fake->setReminderPolicy(null)['is_default']);
    }

    public function test_several_schedules_are_read_at_once(): void
    {
        $client = $this->client($this->json(200, ['data' => [['ref' => 'a', 'host' => 'h', 'spec' => ['schema' => 1]]]]));

        $this->assertSame(['a'], array_keys($client->schedules(['a', 'b', 'a'])));
        $this->assertSame('refs%5B0%5D=a&refs%5B1%5D=b', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame([], $client->schedules([]));
    }

    public function test_the_scheduling_policy_is_read_and_set_on_the_service(): void
    {
        $client = $this->client(
            $this->json(200, ['data' => ['policy' => ['min_buffer_minutes' => 0], 'is_default' => true, 'defaults' => []]]),
            $this->json(200, ['data' => ['policy' => ['min_buffer_minutes' => 5], 'is_default' => false, 'defaults' => []]]),
            $this->json(200, ['data' => ['policy' => ['min_buffer_minutes' => 0], 'is_default' => true, 'defaults' => []]]),
        );

        $this->assertTrue($client->schedulingPolicy()['is_default']);
        $this->assertSame(5, $client->setSchedulingPolicy(['min_buffer_minutes' => 5], 'admin-1')['policy']['min_buffer_minutes']);
        $this->assertSame(['policy' => ['min_buffer_minutes' => 5], 'updated_by' => 'admin-1'], json_decode((string) $this->history[1]['request']->getBody(), true));
        $client->setSchedulingPolicy(null);
        $this->assertSame(['policy' => null], json_decode((string) $this->history[2]['request']->getBody(), true));
        $this->assertSame('/api/v1/scheduling/policy', $this->history[2]['request']->getUri()->getPath());

        $fake = new FakeCalendarClient();
        $this->assertSame(0, $fake->schedulingPolicy()['policy']['min_buffer_minutes']);
        $this->assertSame(5, $fake->setSchedulingPolicy(['min_buffer_minutes' => 5, 'junk' => 1])['policy']['min_buffer_minutes']);
        $this->assertArrayNotHasKey('junk', $fake->schedulingPolicy()['policy']);
        $this->assertTrue($fake->setSchedulingPolicy(null)['is_default']);
    }

    public function test_bookable_refs_are_asked_of_the_service(): void
    {
        $client = $this->client($this->json(200, ['data' => ['a']]), $this->json(200, ['data' => ['a']]));

        $this->assertSame(['a'], $client->bookableRefs());
        $this->assertSame('bookable=1', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame(['a'], $client->bookableRefs(['a', 'b']));
        $this->assertSame('bookable=1&refs%5B0%5D=a&refs%5B1%5D=b', $this->history[1]['request']->getUri()->getQuery());
        $this->assertSame([], $client->bookableRefs([]));

        $fake = (new FakeCalendarClient())->travelTo(new \DateTimeImmutable('2026-10-08'));
        $fake->upsertSchedule('weekly', null, ['schema' => 1, 'timezone' => ['zone' => 'UTC'], 'weekly' => [['days' => ['mon'], 'start' => '09:00', 'end' => '10:00']]]);
        $fake->upsertSchedule('ended', null, ['schema' => 1, 'timezone' => ['zone' => 'UTC'], 'weekly' => [['days' => ['mon'], 'start' => '09:00', 'end' => '10:00', 'valid_until' => '2026-09-01']]]);
        $fake->upsertSchedule('none', null, ['schema' => 1, 'timezone' => ['zone' => 'UTC']]);
        $this->assertSame(['weekly'], $fake->bookableRefs());
        $this->assertSame([], $fake->bookableRefs(['none']));
    }

    public function test_holiday_places_and_rules_are_data_on_the_service(): void
    {
        $client = $this->client(
            $this->json(200, ['data' => [['code' => 'CA', 'name' => 'Canada', 'active' => true]]]),
            $this->json(200, ['data' => [['id' => 1, 'name' => 'Madaraka Day']]]),
            $this->json(200, ['data' => [['id' => 1, 'name' => 'Madaraka Day']]]),
            $this->json(200, ['data' => []]),
            $this->json(200, ['data' => [['date' => '2028-06-01', 'name' => 'Madaraka Day']]]),
        );

        $this->assertSame('CA', $client->holidayRegions()[0]['code']);
        $client->saveHolidayDefinition('KE', null, ['name' => 'Madaraka Day', 'kind' => 'fixed', 'params' => ['month' => 6, 'day' => 1]], 'admin-1');
        $client->saveHolidayDefinition('KE', 1, ['name' => 'Madaraka Day', 'kind' => 'fixed', 'params' => ['month' => 6, 'day' => 1]]);
        $client->deleteHolidayDefinition('KE', 1);
        $this->assertSame('2028-06-01', $client->holidayPreview('KE', 2028)[0]['date']);

        $this->assertSame(['POST', '/api/v1/holiday-definitions/KE'], [$this->history[1]['request']->getMethod(), $this->history[1]['request']->getUri()->getPath()]);
        $this->assertSame(['PUT', '/api/v1/holiday-definitions/KE/1'], [$this->history[2]['request']->getMethod(), $this->history[2]['request']->getUri()->getPath()]);
        $this->assertSame('DELETE', $this->history[3]['request']->getMethod());
        $this->assertSame('year=2028', $this->history[4]['request']->getUri()->getQuery());

        $fake = new FakeCalendarClient();
        $fake->putHolidayRegion('KE', 'Kenya');
        $this->assertContains('KE', array_column($fake->holidayRegions(), 'code'));
        $fake->saveHolidayDefinition('KE', null, ['name' => 'Madaraka Day', 'kind' => 'fixed', 'params' => ['month' => 6, 'day' => 1]]);
        $this->assertSame([['date' => '2028-06-01', 'name' => 'Madaraka Day']], $fake->holidayPreview('KE', 2028));
        $this->assertSame([], $fake->deleteHolidayDefinition('KE', 1));
    }

    public function test_the_fake_keeps_a_rejected_holiday_rejected(): void
    {
        $fake = new FakeCalendarClient();
        $fake->proposeHoliday('GH', '2027-03-09', 'Eid ul-Fitr');
        $fake->rejectHoliday('GH', '2027-03-09');

        $this->assertSame('rejected', $fake->proposeHoliday('GH', '2027-03-10', 'Eid ul-Fitr')['status']);
        $this->assertSame([], $fake->holidays('GH', 2027));
        $this->assertCount(1, $fake->holidays('GH', 2027, ['proposed', 'rejected']));

        $this->expectException(CalendarRequestRejected::class);
        $fake->confirmHoliday('GH', '2027-03-09');
    }

    public function test_participants_hit_their_routes(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => $this->bookingData(['state' => 'confirmed', 'participants' => [['id' => 1, 'role' => 'booker'], ['id' => 2, 'auth_id' => 'sp-1', 'role' => 'attendee']]])]),
            $this->json(200, ['success' => true, 'data' => $this->bookingData(['state' => 'confirmed', 'participants' => [['id' => 1, 'role' => 'booker']]])]),
        );

        $this->assertCount(2, $client->addParticipant('b-1', ['auth_id' => 'sp-1', 'name' => 'Ana'], 'attendee', 'portify:user:1')->participants);
        $this->assertCount(1, $client->removeParticipant('b-1', 2)->participants);

        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/bookings/b-1/participants', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['auth_id' => 'sp-1', 'name' => 'Ana', 'role' => 'attendee', 'actor' => 'portify:user:1'], json_decode((string) $this->history[0]['request']->getBody(), true));
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
        $this->assertSame('/api/v1/bookings/b-1/participants/2', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_the_fake_keeps_several_attendees_apart(): void
    {
        $fake = new FakeCalendarClient();
        $b = $fake->importBooking(['idempotency_key' => 'm:1', 'product_ref' => 'm:1', 'hosts' => ['auth-1'], 'state' => 'confirmed',
            'start' => '2026-10-12T14:00:00Z', 'end' => '2026-10-12T15:00:00Z', 'participants' => [['auth_id' => 'client-9', 'role' => 'booker']]]);

        $fake->addParticipant($b->id, ['auth_id' => 'a-1'], 'attendee');
        $fake->addParticipant($b->id, ['auth_id' => 'a-2'], 'attendee');
        $this->assertCount(3, $fake->addParticipant($b->id, ['auth_id' => 'a-2'], 'attendee')->participants, 'idempotent');

        $this->assertSame('a-2', $fake->markAttendanceFor($b->id, 'attendee', 'attended', ['auth_id' => 'a-2'])['auth_id']);
        $people = array_column($fake->booking($b->id)->participants, null, 'auth_id');
        $this->assertNull($people['a-1']['attendance']);
        $this->assertSame('attended', $people['a-2']['attendance']);

        $this->assertCount(2, $fake->removeParticipant($b->id, (int) $people['a-1']['id'])->participants);
        $this->expectException(CalendarRequestRejected::class);
        $fake->removeParticipant($b->id, (int) $people['client-9']['id']);
    }

    public function test_a_host_who_missed_the_call_is_recorded_on_the_host(): void
    {
        $client = $this->client($this->json(200, ["success" => true, "data" => $this->bookingData(["state" => "completed", "host_attendance" => ["auth-1" => "no_show"]])]));
        $this->assertSame(["auth-1" => "no_show"], $client->markHostAttendance("b-1", "auth-1", "no_show", "portify:system")->raw["host_attendance"]);
        $this->assertSame(["host_auth_id" => "auth-1", "status" => "no_show", "actor" => "portify:system"], json_decode((string) $this->history[0]["request"]->getBody(), true));

        $fake = new FakeCalendarClient();
        $b = $fake->importBooking(["idempotency_key" => "m:2", "product_ref" => "m:2", "hosts" => ["auth-1"], "state" => "completed",
            "start" => "2026-10-12T14:00:00Z", "end" => "2026-10-12T15:00:00Z", "participants" => [["auth_id" => "client-9", "role" => "booker"]]]);
        $this->assertSame("no_show", $fake->markHostAttendance($b->id, "auth-1", "no_show")->raw["host_attendance"]["auth-1"]);
        $this->expectException(CalendarNotFound::class);
        $fake->markHostAttendance($b->id, "stranger", "no_show");
    }

    public function test_the_fake_says_when_and_who_cancelled_or_decided(): void
    {
        $fake = new FakeCalendarClient();
        $b = $fake->importBooking(["idempotency_key" => "m:3", "product_ref" => "m:3", "hosts" => ["auth-1"], "state" => "pending_approval",
            "start" => "2030-10-12T14:00:00Z", "end" => "2030-10-12T15:00:00Z"]);
        $declined = $fake->decline($b->id, "app:user:1", "Not available");
        $this->assertSame(["app:user:1", "Not available"], [$declined->raw["decided_actor"], $declined->raw["decision_reason"]]);
        $this->assertNotNull($declined->raw["decided_at"]);

        $c = $fake->importBooking(["idempotency_key" => "m:4", "product_ref" => "m:4", "hosts" => ["auth-1"], "state" => "confirmed",
            "start" => "2030-10-13T14:00:00Z", "end" => "2030-10-13T15:00:00Z"]);
        $this->assertSame("app:user:2", $fake->cancel($c->id, "product", "app:user:2")->raw["cancelled_actor"]);
    }

    public function test_a_booking_is_erased_and_a_person_forgotten(): void
    {
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => ['removed' => ['bookings' => 1, 'notes' => 2]]]),
            $this->json(200, ['success' => true, 'data' => ['removed' => ['participants' => 3]]]),
        );

        $this->assertSame(['bookings' => 1, 'notes' => 2], $client->eraseBooking('b 1'));
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/bookings/b%201/erase', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['participants' => 3], $client->forgetPerson('maria@example.com', null));
        $this->assertSame(['email' => 'maria@example.com'], json_decode((string) $this->history[1]['request']->getBody(), true));
    }

    public function test_a_participants_name_and_zone_are_corrected(): void
    {
        $this->client($this->json(200, ['success' => true, 'data' => $this->bookingData(['participants' => [['id' => 7, 'zone' => 'America/Toronto']]])]))
            ->updateParticipant('b-1', 7, ['zone' => 'America/Toronto', 'auth_id' => 'ignored'], 'app:user:1');

        $this->assertSame('PATCH', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/bookings/b-1/participants/7', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['zone' => 'America/Toronto', 'actor' => 'app:user:1'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }
    public function test_history_asks_for_the_booking_with_its_changes_and_fills_every_field(): void
    {
        $rows = $this->client($this->json(200, ['success' => true, 'data' => $this->bookingData(['history' => [
            ['event' => 'rescheduled', 'actor' => 'app:user:1', 'at' => '2026-10-02T09:00:00Z',
                'old_start_utc' => '2026-10-12T13:00:00Z', 'new_start_utc' => '2026-10-13T13:00:00Z'],
        ]])]))->history('b-1');

        $this->assertSame('/api/v1/bookings/b-1', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('history=1', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame('2026-10-12T13:00:00Z', $rows[0]['old_start_utc']);
        $this->assertNull($rows[0]['reason']);
        $this->assertArrayHasKey('new_end_utc', $rows[0]);
    }

    public function test_the_fake_records_what_the_service_records(): void
    {
        $fake = (new \Shirahcan\CalendarClient\FakeCalendarClient())->travelTo(new DateTimeImmutable('2026-10-05T08:00:00Z'));
        $b = $fake->createMeeting(['auth-1'], new DateTimeImmutable('2026-10-12T13:00:00Z'), new DateTimeImmutable('2026-10-12T14:00:00Z'), 'k-1');
        $fake->reschedule($b->id, new DateTimeImmutable('2026-10-13T13:00:00Z'), 'app:user:1', 'clash');
        $fake->cancel($b->id, 'host', 'app:user:2', 'no longer needed');

        $history = $fake->history($b->id);
        $this->assertSame(['created', 'rescheduled', 'cancelled'], array_column($history, 'event'));
        $this->assertSame(['2026-10-12T13:00:00Z', '2026-10-13T13:00:00Z'], [$history[1]['old_start_utc'], $history[1]['new_start_utc']]);
        $this->assertSame(['app:user:2', 'no longer needed'], [$history[2]['actor'], $history[2]['reason']]);
    }
}
