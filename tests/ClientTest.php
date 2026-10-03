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

    public function test_the_fake_promotes_a_mirror_into_a_booking(): void
    {
        $fake = new FakeCalendarClient();
        $fake->mirror('meeting:7', ['auth-1'], new DateTimeImmutable('2026-10-12T14:00:00Z'), new DateTimeImmutable('2026-10-12T15:00:00Z'));

        $b = $fake->promoteMirror('meeting:7');

        $this->assertSame('host_created', $b->raw['kind']);
        $this->assertSame([], $fake->mirrors);
        $this->assertSame('2026-10-12T15:30:00Z', $fake->reschedule($b->id, new DateTimeImmutable('2026-10-12T15:00:00Z'), null, null, new DateTimeImmutable('2026-10-12T15:30:00Z'), true)->end->format('Y-m-d\TH:i:s\Z'));
    }
}
