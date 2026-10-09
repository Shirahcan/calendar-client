<?php

namespace Shirahcan\CalendarClient\Tests\Laravel;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\CalendarClientServiceProvider;
use Shirahcan\CalendarClient\FakeCalendarClient;
use Shirahcan\CalendarClient\Laravel\CalendarKit;
use Shirahcan\CalendarClient\Laravel\Notes\MeetingNotesTarget;
use Shirahcan\CalendarClient\Laravel\Notes\NoteAction;
use Shirahcan\CalendarClient\Laravel\Notes\PadAction;
use Shirahcan\CalendarClient\Laravel\Notes\PadContext;
use Shirahcan\CalendarClient\Laravel\Notes\PeopleDirectory;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadAccess;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadViewer;

/** A product's access rule: the "X-User" header names a host of meeting m-1, nothing else. */
class HeaderAccess implements ScratchpadAccess
{
    public static string $booking = '';

    public function viewer(Request $request, string $meetingId): ?ScratchpadViewer
    {
        $user = (string) $request->header('X-User', '');

        return $meetingId === 'm-1' && in_array($user, ['host', 'cohost'], true)
            ? new ScratchpadViewer($meetingId, $user, ['case' => 'c-9'], self::$booking)
            : null;
    }
}

/** A product's own wording and destination ("save to case" is per product). */
class CaseTarget extends MeetingNotesTarget
{
    public static array $saved = [];

    public static bool $refuse = false;

    public function key(): string
    {
        return 'case_notes';
    }

    public function label(ScratchpadViewer $viewer): string
    {
        return 'Save to case notes';
    }

    public function availableFor(ScratchpadViewer $viewer): bool
    {
        return isset($viewer->context['case']);
    }

    public function save(ScratchpadViewer $viewer, string $text): void
    {
        if (self::$refuse) {
            throw new RuntimeException('case closed');
        }
        self::$saved[] = [$viewer->context['case'], $text];
    }
}

/** A product's note action ("Convert to case note"). */
class ConvertAction implements NoteAction
{
    public function key(): string
    {
        return 'convert';
    }

    public function label(ScratchpadViewer $viewer, array $note): string
    {
        return 'Convert to case note';
    }

    public function availableFor(ScratchpadViewer $viewer, array $note): bool
    {
        return empty($note['meta']['case_note_id']);
    }

    public function run(ScratchpadViewer $viewer, array $note): array
    {
        return ['message' => 'Added to the case notes.', 'meta' => ['case_note_id' => 'cn-1']];
    }
}

/** A product's pad action ("Clean up with Porter"): proposes, never applies. */
class TidyAction implements PadAction
{
    public function key(): string
    {
        return 'tidy';
    }

    public function label(ScratchpadViewer $viewer): string
    {
        return 'Clean up';
    }

    public function availableFor(ScratchpadViewer $viewer): bool
    {
        return true;
    }

    public function propose(ScratchpadViewer $viewer, string $text): string
    {
        return strtoupper($text);
    }
}

class HeaderPadContext implements PadContext
{
    public function authorFor(Request $request): ?string
    {
        return $request->header('X-User') ?: null;
    }

    public function describe(array $pad): ?array
    {
        return ['meeting_id' => 'm-1', 'title' => (string) ($pad['booking']['title'] ?? 'Meeting'), 'href' => '/calendar/m-1'];
    }
}

class Names implements PeopleDirectory
{
    public function names(array $authIds): array
    {
        return array_combine($authIds, array_map('ucfirst', $authIds));
    }
}

/** N1/N2: the pad and the notes live on the meeting's booking; products add targets and actions. */
class ScratchpadKitTest extends TestCase
{
    private FakeCalendarClient $fake;

    protected function getPackageProviders($app): array
    {
        return [CalendarClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('calendar-client.scratchpad.access', HeaderAccess::class);
        $app['config']->set('calendar-client.scratchpad.targets', [CaseTarget::class, MeetingNotesTarget::class]);
        $app['config']->set('calendar-client.scratchpad.note_actions', [ConvertAction::class]);
        $app['config']->set('calendar-client.scratchpad.pad_actions', [TidyAction::class]);
        $app['config']->set('calendar-client.scratchpad.pad_context', HeaderPadContext::class);
        $app['config']->set('calendar-client.scratchpad.people', Names::class);
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->group(function () {
            CalendarKit::scratchpadRoutes('/meetings');
            CalendarKit::meetingNotesRoutes('/meetings');
            CalendarKit::pendingPadsRoute('/pads/pending');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        CaseTarget::$saved = [];
        CaseTarget::$refuse = false;
        $this->fake = (new FakeCalendarClient())->travelTo(new DateTimeImmutable('2026-10-05T08:00:00Z'));
        $this->app->instance(CalendarClient::class, $this->fake);
        HeaderAccess::$booking = $this->fake->createMeeting(['host'], new DateTimeImmutable('2026-10-07T15:00:00Z'), new DateTimeImmutable('2026-10-07T16:00:00Z'), 'k1', ['title' => 'Intro call'])->id;
    }

    private function as(string $user): static
    {
        return $this->withHeaders(['X-User' => $user]);
    }

    public function test_each_person_has_their_own_pad_on_the_booking_and_it_autosaves(): void
    {
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'CRS 462'])->assertOk();
        $this->as('cohost')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'mine'])->assertOk();
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'CRS 462, WES'])->assertOk();

        $this->as('host')->getJson('/api/meetings/m-1/scratchpad')
            ->assertOk()
            ->assertJsonPath('data.content', 'CRS 462, WES')
            ->assertJsonPath('data.targets', [
                ['key' => 'case_notes', 'label' => 'Save to case notes'],
                ['key' => 'meeting_notes', 'label' => 'Save to meeting notes'],
            ])
            ->assertJsonPath('data.actions', [['key' => 'tidy', 'label' => 'Clean up']]);
        $this->assertCount(2, $this->fake->drafts);
    }

    public function test_someone_outside_the_meeting_sees_nothing(): void
    {
        $this->as('client')->getJson('/api/meetings/m-1/scratchpad')->assertNotFound();
        $this->as('host')->getJson('/api/meetings/m-2/scratchpad')->assertNotFound();
        $this->as('client')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'x'])->assertNotFound();
        $this->as('client')->getJson('/api/meetings/m-1/notes')->assertNotFound();
        $this->assertSame([], $this->fake->drafts);
    }

    public function test_filing_to_the_products_target_empties_the_pad(): void
    {
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'older'])->assertOk();

        // The text on screen wins over the last autosave.
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'case_notes', 'content' => 'newest'])
            ->assertOk()->assertJsonPath('data.label', 'Save to case notes');

        $this->assertSame([['c-9', 'newest']], CaseTarget::$saved);
        $this->as('host')->getJson('/api/meetings/m-1/scratchpad')->assertJsonPath('data.content', '');
    }

    public function test_the_built_in_target_files_a_note_on_the_booking(): void
    {
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'Book a retest'])->assertOk();
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'meeting_notes'])->assertOk();

        $note = array_values($this->fake->notes)[0];
        $this->assertSame([HeaderAccess::$booking, 'host', 'Book a retest'], [$note['booking_id'], $note['author_auth_id'], $note['content']]);
    }

    public function test_a_target_that_refuses_keeps_the_pad(): void
    {
        CaseTarget::$refuse = true;
        $this->withoutExceptionHandling();
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'keep me'])->assertOk();

        try {
            $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'case_notes']);
            $this->fail('expected the target to refuse');
        } catch (RuntimeException) {
        }

        $this->assertSame('keep me', $this->fake->draft(HeaderAccess::$booking, 'host')['content']);
    }

    public function test_an_unknown_target_or_an_empty_pad_is_refused_plainly(): void
    {
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'meeting_notes'])
            ->assertStatus(422)->assertJsonPath('message', 'The scratchpad is empty, so there is nothing to save.');

        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'x'])->assertOk();
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'nowhere'])->assertStatus(422);
    }

    public function test_a_pad_action_proposes_and_the_pad_is_unchanged_until_accepted(): void
    {
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'funds ok'])->assertOk();

        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/propose', ['action' => 'tidy'])
            ->assertOk()->assertJsonPath('data.proposal', 'FUNDS OK');
        $this->as('host')->getJson('/api/meetings/m-1/scratchpad')->assertJsonPath('data.content', 'funds ok');
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/propose', ['action' => 'nope'])->assertStatus(422);
    }

    public function test_unsettled_pads_are_listed_until_filed_or_discarded(): void
    {
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'follow up'])->assertOk();

        $this->as('host')->getJson('/api/pads/pending')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Intro call')->assertJsonPath('data.0.content', 'follow up');
        $this->as('cohost')->getJson('/api/pads/pending')->assertJsonCount(0, 'data');

        $this->as('host')->deleteJson('/api/meetings/m-1/scratchpad')->assertOk();
        $this->as('host')->getJson('/api/pads/pending')->assertJsonCount(0, 'data');
    }

    public function test_notes_are_filed_read_by_who_may_and_changed_by_their_author_only(): void
    {
        $shared = $this->as('host')->postJson('/api/meetings/m-1/notes', ['content' => 'Client has funds'])
            ->assertCreated()->assertJsonPath('data.author_name', 'Host')->assertJsonPath('data.mine', true)->json('data');
        $this->as('host')->postJson('/api/meetings/m-1/notes', ['content' => 'My doubts', 'private' => true])->assertCreated();

        $this->as('cohost')->getJson('/api/meetings/m-1/notes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mine', false)->assertJsonPath('data.0.actions.0.key', 'convert');
        $this->as('host')->getJson('/api/meetings/m-1/notes')->assertJsonCount(2, 'data');

        $this->as('cohost')->patchJson("/api/meetings/m-1/notes/{$shared['id']}", ['content' => 'hijack'])->assertStatus(422);
        $this->as('host')->patchJson("/api/meetings/m-1/notes/{$shared['id']}", ['content' => 'Client has funds (verified)'])
            ->assertOk()->assertJsonPath('data.content', 'Client has funds (verified)');
        $this->as('cohost')->deleteJson("/api/meetings/m-1/notes/{$shared['id']}")->assertStatus(422);
    }

    public function test_a_note_action_runs_and_remembers_what_it_did(): void
    {
        $note = $this->as('host')->postJson('/api/meetings/m-1/notes', ['content' => 'Recommend SDS'])->json('data');

        $this->as('host')->postJson("/api/meetings/m-1/notes/{$note['id']}/actions/convert")
            ->assertOk()->assertJsonPath('data.message', 'Added to the case notes.')
            ->assertJsonPath('data.note.meta.case_note_id', 'cn-1')->assertJsonPath('data.note.actions', []);
        $this->as('host')->postJson("/api/meetings/m-1/notes/{$note['id']}/actions/convert")->assertStatus(422);
    }

    public function test_a_product_that_mounted_the_routes_without_access_fails_loudly(): void
    {
        config(['calendar-client.scratchpad.access' => null]);
        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        $this->getJson('/api/meetings/m-1/scratchpad');
    }
}
