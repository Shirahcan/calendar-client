<?php

namespace Shirahcan\CalendarClient\Tests\Laravel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Shirahcan\CalendarClient\CalendarClientServiceProvider;
use Shirahcan\CalendarClient\Laravel\CalendarKit;
use Shirahcan\CalendarClient\Laravel\Notes\MeetingNote;
use Shirahcan\CalendarClient\Laravel\Notes\MeetingNotesTarget;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadAccess;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadDraft;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadTarget;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadViewer;

/** A product's access rule: the "X-User" header names a host of meeting m-1, nothing else. */
class HeaderAccess implements ScratchpadAccess
{
    public function viewer(Request $request, string $meetingId): ?ScratchpadViewer
    {
        $user = (string) $request->header('X-User', '');

        return $meetingId === 'm-1' && in_array($user, ['host', 'cohost'], true)
            ? new ScratchpadViewer($meetingId, $user, ['case' => 'c-9'])
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

class ScratchpadKitTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [CalendarClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('calendar-client.scratchpad.access', HeaderAccess::class);
        $app['config']->set('calendar-client.scratchpad.targets', [CaseTarget::class, MeetingNotesTarget::class]);
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('api')->group(fn () => CalendarKit::scratchpadRoutes('/meetings'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        CaseTarget::$saved = [];
        CaseTarget::$refuse = false;
    }

    private function as(string $user): static
    {
        return $this->withHeaders(['X-User' => $user]);
    }

    public function test_each_person_has_their_own_pad_and_it_autosaves(): void
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
            ]);
        $this->assertSame(2, ScratchpadDraft::count());
    }

    public function test_someone_outside_the_meeting_sees_nothing(): void
    {
        $this->as('client')->getJson('/api/meetings/m-1/scratchpad')->assertNotFound();
        $this->as('host')->getJson('/api/meetings/m-2/scratchpad')->assertNotFound();
        $this->as('client')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'x'])->assertNotFound();
        $this->assertSame(0, ScratchpadDraft::count());
    }

    public function test_filing_to_the_products_target_empties_the_pad(): void
    {
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'older'])->assertOk();

        // The text on screen wins over the last autosave.
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'case_notes', 'content' => 'newest'])
            ->assertOk()
            ->assertJsonPath('data.label', 'Save to case notes');

        $this->assertSame([['c-9', 'newest']], CaseTarget::$saved);
        $this->as('host')->getJson('/api/meetings/m-1/scratchpad')->assertJsonPath('data.content', '');
    }

    public function test_the_built_in_target_files_a_meeting_note(): void
    {
        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'Book a retest'])->assertOk();
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'meeting_notes'])->assertOk();

        $note = MeetingNote::sole();
        $this->assertSame(['m-1', 'host', 'Book a retest'], [$note->meeting_id, $note->user_id, $note->content]);
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

        $this->assertSame('keep me', ScratchpadDraft::sole()->content);
    }

    public function test_an_unknown_target_or_an_empty_pad_is_refused_plainly(): void
    {
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'meeting_notes'])
            ->assertStatus(422)->assertJsonPath('message', 'The scratchpad is empty, so there is nothing to save.');

        $this->as('host')->putJson('/api/meetings/m-1/scratchpad', ['content' => 'x'])->assertOk();
        $this->as('host')->postJson('/api/meetings/m-1/scratchpad/commit', ['target' => 'nowhere'])->assertStatus(422);
    }

    public function test_a_product_that_mounted_the_routes_without_access_fails_loudly(): void
    {
        config(['calendar-client.scratchpad.access' => null]);
        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        $this->getJson('/api/meetings/m-1/scratchpad');
    }
}
