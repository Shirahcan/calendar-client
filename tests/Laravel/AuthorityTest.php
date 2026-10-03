<?php

namespace Shirahcan\CalendarClient\Tests\Laravel;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\CalendarClientServiceProvider;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\FakeCalendarClient;
use Shirahcan\CalendarClient\Laravel\Bookings\AbstractBookingSubject;
use Shirahcan\CalendarClient\Laravel\Bookings\AuthorityObserver;
use Shirahcan\CalendarClient\Laravel\Jobs\CancelBooking;
use Shirahcan\CalendarClient\Laravel\Jobs\VerifyLanded;
use Shirahcan\CalendarClient\Laravel\Refusal;

class KitCall extends Model
{
    use SoftDeletes;

    protected $table = 'kit_calls';

    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'guests' => 'array'];

    protected static function booted(): void
    {
        // ⚠ A block body, NOT an arrow function: an '-ing' listener that RETURNS a value halts the
        // event, and every later listener (the authority observer included) never runs.
        static::creating(function (KitCall $c): void {
            $c->uuid ??= (string) Str::uuid();
        });
    }
}

class KitCallSubject extends AbstractBookingSubject
{
    public static bool $on = true;

    protected string $model = KitCall::class;

    protected string $refPrefix = 'call';

    public function enabled(): bool
    {
        return self::$on;
    }

    public function hosts(Model $m): array
    {
        return array_values(array_unique(array_filter(array_merge([$m->host_id], (array) $m->guests))));
    }

    public function occupies(Model $m): bool
    {
        return in_array($m->status, ['scheduled', 'confirmed'], true);
    }

    public function start(Model $m): CarbonInterface
    {
        return $m->starts_at;
    }

    public function end(Model $m): CarbonInterface
    {
        return $m->ends_at;
    }

    public function moveAttributes(): array
    {
        return ['starts_at', 'ends_at'];
    }

    public function peopleAttributes(): array
    {
        return ['host_id', 'guests'];
    }

    public function detailAttributes(): array
    {
        return ['title'];
    }

    public function describe(Model $m): ?array
    {
        return ['title' => $m->title, 'description' => null, 'location' => 'https://x.test/'.$m->uuid, 'write_to' => [$m->host_id]];
    }

    public function mirrorRef(Model $m): ?string
    {
        return 'call-mirror:'.$m->id;
    }

    public function externalEvents(Model $m): array
    {
        return [['provider' => 'google', 'account_email' => 'host@example.com', 'event_id' => 'gcal-'.$m->id]];
    }
}

class KitCallObserver extends AuthorityObserver
{
    protected function subjectClass(): string
    {
        return KitCallSubject::class;
    }
}

/** K6: the whole booking lifecycle through the kit, on a product-like model. */
class AuthorityTest extends TestCase
{
    use RefreshDatabase;

    private FakeCalendarClient $fake;

    protected function getPackageProviders($app): array
    {
        return [CalendarClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('kit_calls', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('host_id');
            $t->json('guests')->nullable();
            $t->string('status');
            $t->string('title')->nullable();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('calendar_booking_id', 40)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->fake = (new FakeCalendarClient())->travelTo(new DateTimeImmutable('2026-10-05T08:00:00Z'));
        $this->app->instance(CalendarClient::class, $this->fake);
        KitCallSubject::$on = true;
        KitCall::observe(KitCallObserver::class);
    }

    private function newCall(array $over = []): KitCall
    {
        return KitCall::create(array_merge(['host_id' => 'host', 'guests' => ['guest'], 'status' => 'confirmed', 'title' => 'Call',
            'starts_at' => '2026-10-07 15:00:00', 'ends_at' => '2026-10-07 15:30:00'], $over));
    }

    private function remote(KitCall $c): array
    {
        return $this->fake->bookings[$c->fresh()->calendar_booking_id];
    }

    public function test_a_record_is_booked_first_for_everyone_and_its_copy_goes_to_the_host_only(): void
    {
        $c = $this->newCall();

        $r = $this->remote($c);
        $this->assertSame(['host', 'guest'], $r['hosts']);
        $this->assertSame('call:'.$c->uuid, $r['idempotency_key']);
        $this->assertSame(['Call', ['host']], [$r['title'], $r['write_hosts']]);
    }

    public function test_a_clash_or_an_unreachable_service_writes_nothing(): void
    {
        $this->newCall();
        try {
            $this->newCall(['starts_at' => '2026-10-07 15:15:00', 'ends_at' => '2026-10-07 15:45:00', 'guests' => []]);
            $this->fail('A clash was written.');
        } catch (Refusal $e) {
            $this->assertSame(409, $e->status());
        }
        $this->fake->failNext(new CalendarServiceUnavailable('down'));
        try {
            $this->newCall(['starts_at' => '2026-10-08 15:00:00', 'ends_at' => '2026-10-08 15:30:00']);
            $this->fail('Booked blind.');
        } catch (Refusal $e) {
            $this->assertSame(503, $e->status());
        }
        $this->assertSame(1, KitCall::count());
    }

    public function test_move_people_end_reopen_and_delete_follow_the_record(): void
    {
        $c = $this->newCall();
        $c->update(['starts_at' => '2026-10-08 16:00:00', 'ends_at' => '2026-10-08 16:30:00']);
        $this->assertSame('2026-10-08T16:00:00Z', $this->remote($c)['start_utc']);

        $c->update(['guests' => ['guest', 'panel']]);
        $this->assertSame(['host', 'guest', 'panel'], $this->remote($c)['hosts']);

        $first = $c->fresh()->calendar_booking_id;
        $c->update(['status' => 'cancelled']);
        $this->assertSame('cancelled', $this->fake->bookings[$first]['state']);
        $c->update(['title' => 'Edited while cancelled']);   // no second cancel, no error

        $c->update(['status' => 'confirmed']);
        $this->assertNotSame($first, $c->fresh()->calendar_booking_id, 'reopened = booked afresh');
        $this->assertSame('confirmed', $this->remote($c)['state']);

        $id = $c->fresh()->calendar_booking_id;
        $c->delete();
        $this->assertSame('cancelled', $this->fake->bookings[$id]['state']);
    }

    public function test_ending_never_waits_for_the_service(): void
    {
        Bus::fake([CancelBooking::class]);
        $c = $this->newCall();
        $this->fake->failNext(new CalendarServiceUnavailable('down'));

        $c->update(['status' => 'cancelled']);

        $this->assertSame('cancelled', $c->fresh()->status);
        Bus::assertDispatched(CancelBooking::class);
    }

    public function test_a_record_from_before_the_cutover_is_linked_on_its_first_change(): void
    {
        KitCallSubject::$on = false;
        $c = $this->newCall();
        $this->fake->mirror('call-mirror:'.$c->id, ['host', 'guest'], new DateTimeImmutable('2026-10-07T15:00:00Z'), new DateTimeImmutable('2026-10-07T15:30:00Z'));
        $this->assertNull($c->fresh()->calendar_booking_id);

        KitCallSubject::$on = true;
        $c->update(['starts_at' => '2026-10-07 17:00:00', 'ends_at' => '2026-10-07 17:30:00']);

        $promote = array_values(array_filter($this->fake->calls, fn ($x) => $x[0] === 'promoteMirror'))[0][1];
        $this->assertSame([['provider' => 'google', 'account_email' => 'host@example.com', 'event_id' => 'gcal-'.$c->id]], $promote[1]);
        $this->assertSame('2026-10-07T17:00:00Z', $this->remote($c)['start_utc']);
    }

    public function test_a_booking_whose_record_never_landed_is_given_back(): void
    {
        $orphan = $this->fake->createMeeting(['host'], new DateTimeImmutable('2026-10-09T10:00:00Z'), new DateTimeImmutable('2026-10-09T10:30:00Z'), 'call:never-saved');

        (new VerifyLanded(KitCallSubject::class, 'call:never-saved', $orphan->id))->handle();

        $this->assertSame('cancelled', $this->fake->bookings[$orphan->id]['state']);

        $c = $this->newCall();
        (new VerifyLanded(KitCallSubject::class, 'call:'.$c->uuid, $c->fresh()->calendar_booking_id))->handle();
        $this->assertSame('confirmed', $this->remote($c)['state'], 'a record that landed keeps its time');
    }
}
