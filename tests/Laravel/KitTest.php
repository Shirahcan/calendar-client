<?php

namespace Shirahcan\CalendarClient\Tests\Laravel;

use DateTimeImmutable;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\CalendarClientServiceProvider;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\FakeCalendarClient;
use Shirahcan\CalendarClient\Laravel\CalendarKit;
use Shirahcan\CalendarClient\Laravel\Events\CalendarServiceEventReceived;
use Shirahcan\CalendarClient\Laravel\Models\CalendarServiceEvent;
use Shirahcan\CalendarClient\Laravel\Refusal;
use Shirahcan\CalendarClient\Laravel\RendersRefusals;
use Shirahcan\CalendarClient\Laravel\Models\SyncLedgerEntry;
use Shirahcan\CalendarClient\Laravel\Seam;
use Shirahcan\CalendarClient\Laravel\SyncLedger;

/** K3 + K4: the product-side kit inside a real Laravel app. */
class KitTest extends TestCase
{
    use RefreshDatabase;

    private FakeCalendarClient $fake;

    private const SECRET = 'czNjcmV0LXMzY3JldC1zM2NyZXQtczNjcmV0LXMzY3I=';

    protected function getPackageProviders($app): array
    {
        return [CalendarClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('calendar-client.callback_secret', self::SECRET);
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->group(function () {
            CalendarKit::webhookRoute('/webhooks/calendar-service');
            Route::get('/refuse', fn () => throw (new Seam())->refusal(new \Shirahcan\CalendarClient\Exceptions\SlotUnavailable('taken', 'slot_unavailable', 409)));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = (new FakeCalendarClient())->travelTo(new DateTimeImmutable('2026-10-05T08:00:00Z'));
        $this->app->instance(CalendarClient::class, $this->fake);
    }

    private function meeting(string $key = 'booking:a', array $hosts = ['emp', 'cand']): \Shirahcan\CalendarClient\Booking
    {
        return (new Seam())->createMeeting($hosts, new DateTimeImmutable('2026-10-07T15:00:00Z'), new DateTimeImmutable('2026-10-07T15:30:00Z'), $key);
    }

    public function test_a_taken_time_is_a_409_refusal_with_a_message_a_person_can_act_on(): void
    {
        $this->meeting();

        try {
            $this->meeting('booking:b', ['emp']);
            $this->fail('A clash was booked.');
        } catch (Refusal $e) {
            $this->assertTrue($e->isRace());
            $this->assertSame(409, $e->status());
            $this->assertSame(Seam::TAKEN, $e->getMessage());
            $this->assertSame('slot_unavailable', $e->code());
        }
    }

    public function test_an_unreachable_service_is_a_503_refusal_never_a_fallback(): void
    {
        $this->fake->failNext(new CalendarServiceUnavailable('down'));

        $this->expectExceptionObject(new Refusal(Seam::UNAVAILABLE, new CalendarServiceUnavailable('down')));
        $this->meeting();
    }

    public function test_ending_is_idempotent_and_the_copy_goes_only_to_hosts(): void
    {
        $b = $this->meeting();
        $seam = new Seam();
        $seam->cancel($b->id);
        $seam->cancel($b->id);   // already cancelled: the outcome asked for
        $this->assertSame('cancelled', $this->fake->bookings[$b->id]['state']);

        $c = $this->meeting('booking:c');
        $seam->describe($c->id, 'Interview', 'Notes', 'https://x.test/room', ['emp', 'stranger']);
        $this->assertSame(['emp'], $this->fake->bookings[$c->id]['write_hosts'], 'a non-host is never written to');
        $this->assertNull($seam->promote('booking:none'), 'no mirror: nothing to promote');
    }

    public function test_a_refusal_on_an_api_route_answers_with_its_status_and_reason(): void
    {
        RendersRefusals::register(new Exceptions($this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)));

        $this->getJson('/api/refuse')->assertStatus(409)->assertExactJson(['success' => false, 'message' => Seam::TAKEN]);
    }

    private function deliver(array $body, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($body);
        $ts = (string) time();
        $sig = $signature ?? base64_encode(hash_hmac('sha256', $ts.'.'.$raw, base64_decode(self::SECRET), true));

        return $this->call('POST', '/api/webhooks/calendar-service', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CALENDAR_SIGNATURE' => $sig, 'HTTP_X_CALENDAR_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_event_is_recorded_and_announced_once_and_a_bad_one_refused(): void
    {
        Event::fake([CalendarServiceEventReceived::class]);
        $body = ['event' => 'booking.cancelled', 'event_id' => 'e1', 'booking' => ['id' => 'b-1', 'product_ref' => 'booking:abc']];

        $this->deliver($body)->assertOk();
        $this->deliver($body)->assertOk();   // a redelivery
        $this->deliver($body, 'nope')->assertStatus(401);
        $this->deliver(['event' => 'x'])->assertStatus(422);

        $this->assertSame(1, CalendarServiceEvent::where('event_id', 'e1')->count());
        $row = CalendarServiceEvent::first();
        $this->assertSame(['b-1', 'booking:abc'], [$row->bookingId(), $row->productRef()]);
        Event::assertDispatchedTimes(CalendarServiceEventReceived::class, 1);
    }

    public function test_the_ledger_sends_once_records_refusals_and_failures_and_never_throws(): void
    {
        $sent = 0;
        $send = function (CalendarClient $c) use (&$sent) {
            $sent++;
            $c->upsertHost('emp', 'Emp', 'UTC');
        };

        $this->assertSame(SyncLedger::SYNCED, SyncLedger::sync('person', 'emp', ['v' => 1], [], $send));
        $this->assertSame(SyncLedger::UNCHANGED, SyncLedger::sync('person', 'emp', ['v' => 1], ['mixed_durations:30,60'], $send));
        $this->assertSame(1, $sent);
        $this->assertSame(['mixed_durations:30,60'], SyncLedgerEntry::for('person', 'emp')->notes, 'notes are kept current without a call');
        $this->assertTrue(SyncLedger::isCurrent('person', 'emp'));

        $this->fake->failNext(new CalendarServiceUnavailable('down'));
        $this->assertSame(SyncLedger::FAILED, SyncLedger::sync('person', 'emp', ['v' => 2], [], $send));
        $this->assertFalse(SyncLedger::isCurrent('person', 'emp'));
        $this->assertSame(SyncLedger::SYNCED, SyncLedger::sync('person', 'emp', ['v' => 2], [], $send), 'a failure is retried');

        $this->assertSame(SyncLedger::REFUSED, SyncLedger::refuse('person', 'emp', ['mixed_zones:A,B']));
        $this->assertSame('mixed_zones:A,B', SyncLedgerEntry::for('person', 'emp')->problem);
        $this->assertSame(SyncLedger::SYNCED, SyncLedger::sync('person', 'emp', ['v' => 2], [], $send), 'a refused subject is sent again once fixed');
    }

    public function test_no_secret_refuses_everything(): void
    {
        config(['calendar-client.callback_secret' => '']);

        $this->deliver(['event' => 'booking.cancelled', 'event_id' => 'e2'])->assertStatus(401);
        $this->assertSame(0, CalendarServiceEvent::count());
    }
}
