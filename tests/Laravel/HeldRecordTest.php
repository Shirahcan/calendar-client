<?php

namespace Shirahcan\CalendarClient\Tests\Laravel;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use Shirahcan\CalendarClient\Booking;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\CalendarClientServiceProvider;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable;
use Shirahcan\CalendarClient\FakeCalendarClient;
use Shirahcan\CalendarClient\Laravel\Bookings\AbstractBookingSubject;
use Shirahcan\CalendarClient\Laravel\Bookings\AuthorityObserver;
use Shirahcan\CalendarClient\Laravel\Bookings\HeldBookings;
use Shirahcan\CalendarClient\Laravel\Bookings\HeldBookingSubject;
use Shirahcan\CalendarClient\Laravel\Bookings\HeldInCalendarService;
use Shirahcan\CalendarClient\Laravel\Refusal;

class HeldMeeting extends Model
{
    use HeldInCalendarService;

    protected $table = 'held_meetings';

    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    protected static function calendarSubject(): string
    {
        return HeldMeetingSubject::class;
    }

    protected static function booted(): void
    {
        static::creating(function (HeldMeeting $m): void {
            $m->uuid ??= (string) Str::uuid();
        });
    }
}

class HeldMeetingSubject extends AbstractBookingSubject implements HeldBookingSubject
{
    protected string $model = HeldMeeting::class;

    protected string $refPrefix = 'held';

    public function enabled(): bool
    {
        return true;
    }

    public function hosts(Model $m): array
    {
        return [$m->host_id];
    }

    public function occupies(Model $m): bool
    {
        return in_array($m->getAttributes()['status'] ?? null, ['pending', 'scheduled', 'confirmed'], true);
    }

    public function start(Model $m): CarbonInterface
    {
        return Carbon::parse($m->getAttributes()['starts_at'], 'UTC');
    }

    public function end(Model $m): CarbonInterface
    {
        return Carbon::parse($m->getAttributes()['ends_at'], 'UTC');
    }

    public function moveAttributes(): array
    {
        return ['starts_at', 'ends_at'];
    }

    public function peopleAttributes(): array
    {
        return ['host_id'];
    }

    public function detailAttributes(): array
    {
        return ['title'];
    }

    public function approval(Model $m): ?string
    {
        if (! $m->isDirty('status') || $m->getRawOriginal('status') !== 'pending') {
            return null;
        }

        return $this->occupies($m) ? 'approve' : 'decline';
    }

    public function heldAttributes(): array
    {
        return ['status', 'starts_at', 'ends_at', 'title', 'reason'];
    }

    public function hydrate(?Booking $b, Model $m): array
    {
        if ($b === null) {
            return ['status' => 'cancelled'];
        }
        $noShow = collect($b->participants)->contains(fn ($p) => $p['role'] === 'booker' && $p['attendance'] === 'no_show');

        return [
            'status' => match ($b->state) {
                Booking::PENDING => 'pending',
                Booking::CONFIRMED => collect($b->participants)->contains(fn ($p) => $p['role'] === 'booker' && ($p['response'] ?? null) === 'accepted') ? 'confirmed' : 'scheduled',
                Booking::COMPLETED => $noShow ? 'no_show' : 'completed',
                default => 'cancelled',
            },
            'starts_at' => $m->fromDateTime(Carbon::instance($b->start)),
            'ends_at' => $m->fromDateTime(Carbon::instance($b->end)),
            'title' => $b->raw['title'] ?? null,
            'reason' => $b->raw['cancel_reason'] ?? null,
        ];
    }

    public function outcome(Model $m): ?array
    {
        return match ($m->getAttributes()['status'] ?? null) {
            'completed' => ['complete' => true, 'attendance' => 'attended', 'role' => 'booker', 'who' => ['auth_id' => 'client-1']],
            'no_show' => ['complete' => true, 'attendance' => 'no_show', 'role' => 'booker', 'who' => ['auth_id' => 'client-1']],
            default => null,
        };
    }

    public function response(Model $m): ?array
    {
        return ($m->getAttributes()['status'] ?? null) === 'confirmed' && $m->getRawOriginal('status') !== 'confirmed'
            ? ['role' => 'booker', 'response' => 'accepted', 'who' => ['auth_id' => 'client-1']]
            : null;
    }

    public function cancelReason(Model $m): ?string
    {
        return $m->getAttributes()['reason'] ?? null;
    }

    public function details(Model $m): array
    {
        return ['title' => $m->getAttributes()['title'] ?? null];
    }

    public function participants(Model $m): array
    {
        return [['auth_id' => 'client-1', 'name' => 'Client', 'role' => 'booker']];
    }

    public function endedState(Model $m): ?string
    {
        return ($m->getAttributes()['status'] ?? null) === 'cancelled' ? Booking::CANCELLED : Booking::COMPLETED;
    }
}

class HeldMeetingObserver extends AuthorityObserver
{
    protected function subjectClass(): string
    {
        return HeldMeetingSubject::class;
    }
}

/** A record whose facts live in calendar-service only: the product's row is a handle. */
class HeldRecordTest extends TestCase
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
        Schema::create('held_meetings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('host_id');
            $t->string('status')->nullable();
            $t->string('title')->nullable();
            $t->string('reason')->nullable();
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->string('calendar_booking_id', 40)->nullable();
            $t->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->fake = (new FakeCalendarClient())->travelTo(new DateTimeImmutable('2026-10-05T08:00:00Z'));
        $this->app->instance(CalendarClient::class, $this->fake);
        HeldMeeting::observe(HeldMeetingObserver::class);
    }

    private function book(array $over = []): HeldMeeting
    {
        return HeldMeeting::create(array_merge(['host_id' => 'host', 'status' => 'scheduled', 'title' => 'Intro',
            'starts_at' => '2026-10-07 15:00:00', 'ends_at' => '2026-10-07 15:30:00'], $over));
    }

    /** A new request: nothing this one read is remembered. */
    private function fresh(HeldMeeting $m): HeldMeeting
    {
        $this->app->forgetScopedInstances();

        return HeldMeeting::query()->findOrFail($m->id);
    }

    private function row(HeldMeeting $m): object
    {
        return DB::table('held_meetings')->where('id', $m->id)->first();
    }

    public function test_the_product_stores_a_handle_and_the_service_holds_the_meeting(): void
    {
        $m = $this->book();

        $remote = $this->fake->bookings[$m->calendar_booking_id];
        $this->assertSame(['Intro', 'held:'.$m->uuid, 'client-1'], [$remote['title'], $remote['product_ref'], $remote['participants'][0]['auth_id']]);
        $row = $this->row($m);
        $this->assertSame([null, null, null, null], [$row->status, $row->title, $row->starts_at, $row->ends_at]);

        $read = $this->fresh($m);
        $this->assertSame(['scheduled', 'Intro', '2026-10-07 15:00:00'], [$read->status, $read->title, $read->starts_at->format('Y-m-d H:i:s')]);
    }

    public function test_a_list_reads_every_booking_in_one_call(): void
    {
        $this->book();
        $this->book(['starts_at' => '2026-10-08 15:00:00', 'ends_at' => '2026-10-08 15:30:00']);
        $this->book(['starts_at' => '2026-10-09 15:00:00', 'ends_at' => '2026-10-09 15:30:00']);
        $this->app->forgetScopedInstances();
        $this->fake->calls = [];

        $statuses = HeldMeeting::query()->get()->pluck('status')->all();

        $this->assertSame(['scheduled', 'scheduled', 'scheduled'], $statuses);
        $this->assertCount(1, $this->fake->callsTo('bookings'));
    }

    public function test_a_move_a_retitle_and_a_cancel_go_to_the_service_and_read_back(): void
    {
        $m = $this->fresh($this->book());

        $m->update(['starts_at' => '2026-10-07 16:00:00', 'ends_at' => '2026-10-07 16:45:00', 'title' => 'Follow-up']);
        $this->assertTrue($m->wasChanged('title'));
        $this->assertSame('Intro', $m->getPrevious()['title']);
        $read = $this->fresh($m);
        $this->assertSame(['Follow-up', '2026-10-07 16:45:00'], [$read->title, $read->ends_at->format('Y-m-d H:i:s')]);

        $read->update(['status' => 'cancelled', 'reason' => 'Client travelling']);
        $remote = $this->fake->bookings[$m->calendar_booking_id];
        $this->assertSame([Booking::CANCELLED, 'Client travelling'], [$remote['state'], $remote['cancel_reason']]);
        $this->assertSame(['cancelled', 'Client travelling'], [$this->fresh($m)->status, $this->fresh($m)->reason]);
        $this->assertNull($this->row($m)->status);
    }

    public function test_a_refused_change_writes_nothing_and_the_meeting_stands(): void
    {
        $m = $this->fresh($this->book());
        $this->fake->failNext(new CalendarServiceUnavailable('down'));

        try {
            $m->update(['status' => 'cancelled']);
            $this->fail('A cancel the service never took was reported as done.');
        } catch (Refusal $e) {
            $this->assertSame(503, $e->status());
        }

        $this->assertSame('scheduled', $this->fresh($m)->status);
    }

    public function test_completed_and_no_show_are_the_services_outcome(): void
    {
        $m = $this->fresh($this->book());
        $this->fake->travelTo(new DateTimeImmutable('2026-10-07T15:20:00Z'));

        $m->update(['status' => 'no_show']);

        $remote = $this->fake->bookings[$m->calendar_booking_id];
        $this->assertSame([Booking::COMPLETED, 'no_show'], [$remote['state'], $remote['participants'][0]['attendance']]);
        $read = $this->fresh($m);
        $this->assertSame('no_show', $read->status);

        $read->update(['status' => 'completed']);   // they did come after all
        $this->assertSame('completed', $this->fresh($m)->status);
    }

    public function test_the_client_confirming_is_their_answer_on_the_booking(): void
    {
        $m = $this->fresh($this->book());

        $m->update(['status' => 'confirmed']);

        $this->assertSame('accepted', $this->fake->bookings[$m->calendar_booking_id]['participants'][0]['response']);
        $this->assertSame('confirmed', $this->fresh($m)->status);

        $born = $this->book(['status' => 'confirmed', 'starts_at' => '2026-10-08 15:00:00', 'ends_at' => '2026-10-08 15:30:00']);
        $this->assertSame('confirmed', $this->fresh($born)->status);
    }

    public function test_a_pending_meeting_is_approved_or_declined_there(): void
    {
        $m = $this->fresh($this->book(['status' => 'pending']));
        // A host-created meeting is confirmed at once; make it pending as a booking flow would.
        $this->fake->bookings[$m->calendar_booking_id]['state'] = Booking::PENDING;
        $m = $this->fresh($m);
        $this->assertSame('pending', $m->status);

        $m->update(['status' => 'cancelled', 'reason' => 'Not a fit']);

        $this->assertSame(Booking::DECLINED, $this->fake->bookings[$m->calendar_booking_id]['state']);
        $this->assertSame('cancelled', $this->fresh($m)->status);
    }

    public function test_a_meeting_written_after_it_ended_is_handed_over_as_history(): void
    {
        $m = $this->book(['status' => 'completed', 'starts_at' => '2026-10-01 15:00:00', 'ends_at' => '2026-10-01 15:30:00']);

        $remote = $this->fake->bookings[$m->calendar_booking_id];
        $this->assertSame([Booking::COMPLETED, 'imported'], [$remote['state'], $remote['kind']]);
        $this->assertSame('completed', $this->fresh($m)->status);
    }

    public function test_a_record_written_onto_a_confirmed_hold_carries_its_text_there(): void
    {
        $booking = $this->fake->createMeeting(['host'], new DateTimeImmutable('2026-10-07T15:00:00Z'), new DateTimeImmutable('2026-10-07T15:30:00Z'), 'hold-1');

        $m = $this->book(['calendar_booking_id' => $booking->id, 'title' => 'Visa strategy']);

        $this->assertSame('Visa strategy', $this->fake->bookings[$booking->id]['title']);
        $this->assertSame(['scheduled', 'Visa strategy'], [$this->fresh($m)->status, $this->fresh($m)->title]);
    }

    public function test_a_booking_the_service_no_longer_has_reads_as_ended(): void
    {
        $m = $this->book();
        unset($this->fake->bookings[$m->calendar_booking_id]);

        $this->assertSame('cancelled', $this->fresh($m)->status);
    }

    public function test_a_row_from_before_the_move_is_linked_with_its_text_on_its_first_change(): void
    {
        $m = HeldMeeting::withoutEvents(fn () => HeldMeeting::create(['uuid' => (string) Str::uuid(), 'host_id' => 'host', 'status' => 'scheduled',
            'title' => 'Legacy', 'starts_at' => '2026-10-07 15:00:00', 'ends_at' => '2026-10-07 15:30:00']));

        $m->update(['starts_at' => '2026-10-07 16:00:00', 'ends_at' => '2026-10-07 16:30:00']);

        $this->assertNotNull($m->calendar_booking_id);
        $this->assertSame('Legacy', $this->fake->bookings[$m->calendar_booking_id]['title']);
        $this->assertSame(['Legacy', '2026-10-07 16:00:00'], [$this->fresh($m)->title, $this->fresh($m)->starts_at->format('Y-m-d H:i:s')]);
    }

    public function test_a_row_without_a_booking_keeps_its_own_columns(): void
    {
        HeldMeeting::withoutEvents(fn () => HeldMeeting::create(['uuid' => (string) Str::uuid(), 'host_id' => 'host', 'status' => 'scheduled',
            'title' => 'Legacy', 'starts_at' => '2026-10-07 15:00:00', 'ends_at' => '2026-10-07 15:30:00']));

        $read = HeldMeeting::query()->firstOrFail();
        $this->assertSame(['scheduled', 'Legacy'], [$read->status, $read->title]);
        $this->assertSame([], $this->fake->callsTo('bookings'));
        $this->assertInstanceOf(HeldBookings::class, app(HeldBookings::class));
    }
}
