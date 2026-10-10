<?php

namespace Shirahcan\CalendarClient\Tests\Laravel;

use DateTimeImmutable;
use Orchestra\Testbench\TestCase;
use Shirahcan\CalendarClient\Booking;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\CalendarClientServiceProvider;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\FakeCalendarClient;
use Shirahcan\CalendarClient\Laravel\Bookings\SealedHold;
use Shirahcan\CalendarClient\Laravel\HostConnections;

class HoldAndConnectionsTest extends TestCase
{
    private FakeCalendarClient $fake;

    protected function getPackageProviders($app): array
    {
        return [CalendarClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeCalendarClient();
        $this->app->instance(CalendarClient::class, $this->fake);
    }

    private function booking(): Booking
    {
        return Booking::fromArray([
            'id' => 'bk_1', 'state' => 'held', 'start_utc' => '2026-10-13T13:00:00Z', 'end_utc' => '2026-10-13T13:30:00Z',
            'hosts' => [], 'participants' => [], 'hold_expires_at' => '2026-10-10T15:00:00Z',
        ]);
    }

    public function test_a_sealed_hold_round_trips_and_only_its_own_session_owns_it(): void
    {
        $token = SealedHold::for($this->booking(), 'sess-1', ['client_id' => 'c-9', 'zone' => 'Africa/Lagos'])->seal();

        $this->assertStringNotContainsString('bk_1', $token);
        $hold = SealedHold::open($token);
        $this->assertSame('bk_1', $hold->bookingId);
        $this->assertSame('Africa/Lagos', $hold->get('zone'));
        $this->assertSame(30, $hold->durationMinutes());
        $this->assertTrue($hold->belongsTo('sess-1'));
        $this->assertFalse($hold->belongsTo('sess-2'));
        $this->assertFalse($hold->belongsTo(null));
    }

    public function test_anything_not_sealed_as_a_hold_opens_to_nothing(): void
    {
        $token = SealedHold::for($this->booking(), 's')->seal();

        $this->assertNull(SealedHold::open(str_repeat('a', 64)));
        $this->assertNull(SealedHold::open(substr($token, 0, -4).'AAAA'));
        $this->assertNull(SealedHold::open(''));
        $this->assertNull(SealedHold::open(\Illuminate\Support\Facades\Crypt::encryptString('{"p":"other"}')));
    }

    public function test_host_connections_name_the_ones_to_reconnect_and_never_guess_on_an_outage(): void
    {
        $this->fake->connections['host-1'] = [
            ['id' => 1, 'provider' => 'google', 'status' => 'active'],
            ['id' => 2, 'provider' => 'google', 'status' => 'needs_reauth'],
            ['id' => 3, 'provider' => 'microsoft', 'status' => 'active', 'reconnect_recommended' => true],
        ];
        $connections = $this->app->make(HostConnections::class);

        $this->assertSame([2, 3], array_column($connections->needingReconnect('host-1'), 'id'));
        $this->assertSame([], $connections->for(''));

        $this->fake->failNext(new CalendarServiceUnavailable('down', 'unreachable'));
        $this->assertNull($this->app->make(HostConnections::class)->for('host-2'));
    }
}
