<?php

namespace Shirahcan\CalendarClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;
use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\Laravel\Notes\PadAction;
use Shirahcan\CalendarClient\Laravel\Notes\PadContext;
use Shirahcan\CalendarClient\Laravel\Notes\Scratchpad;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadAccess;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadTarget;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadViewer;

/**
 * The scratchpad's HTTP surface, the same in every product (CalendarKit::scratchpadRoutes):
 *
 *   GET    {prefix}/{meeting}/scratchpad                 the person's pad, targets and actions
 *   PUT    {prefix}/{meeting}/scratchpad                 autosave { content }
 *   DELETE {prefix}/{meeting}/scratchpad                 discard it
 *   POST   {prefix}/{meeting}/scratchpad/commit          file it { target, content? } and empty it
 *   POST   {prefix}/{meeting}/scratchpad/propose         { action, content? } -> { proposal }
 *   GET    {pendingUri}                                  every pad the person has not settled
 *
 * The pad lives on the meeting's booking in calendar-service (plan N1). Who may use it is the
 * product's ScratchpadAccess; anyone else gets 404, like a meeting that does not exist.
 */
class ScratchpadController extends Controller
{
    public function __construct(private readonly Scratchpad $pad) {}

    public function show(Request $request, string $meeting): JsonResponse
    {
        $viewer = $this->viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }

        $draft = $this->pad->draft($viewer);

        return response()->json(['data' => [
            'content' => $draft['content'],
            'updated_at' => $draft['updated_at'],
            'targets' => array_map(fn (ScratchpadTarget $t) => ['key' => $t->key(), 'label' => $t->label($viewer)], $this->pad->targets($viewer)),
            'actions' => array_map(fn (PadAction $a) => ['key' => $a->key(), 'label' => $a->label($viewer)], $this->pad->actions($viewer)),
        ]]);
    }

    public function save(Request $request, string $meeting): JsonResponse
    {
        $viewer = $this->viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }

        $data = $request->validate(['content' => ['present', 'nullable', 'string', 'max:'.$this->maxChars()]]);
        $draft = $this->pad->saveDraft($viewer, (string) ($data['content'] ?? ''));

        return response()->json(['data' => ['updated_at' => $draft['updated_at']]]);
    }

    public function discard(Request $request, string $meeting): JsonResponse
    {
        $viewer = $this->viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }
        $this->pad->discard($viewer);

        return response()->json(['data' => ['discarded' => true]]);
    }

    public function commit(Request $request, string $meeting): JsonResponse
    {
        $viewer = $this->viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'target' => ['required', 'string', 'max:64'],
            'content' => ['nullable', 'string', 'max:'.$this->maxChars()],
        ]);
        // The text on screen if the browser sent it (it may be newer than the last autosave).
        $text = trim((string) ($data['content'] ?? $this->pad->draft($viewer)['content']));
        if ($text === '') {
            return response()->json(['message' => 'The scratchpad is empty, so there is nothing to save.'], 422);
        }

        try {
            $target = $this->pad->commit($viewer, (string) $data['target'], $text);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['committed' => true, 'target' => $target->key(), 'label' => $target->label($viewer)]]);
    }

    /** A pad action's proposal for the text. Nothing is saved: the person accepts or discards it. */
    public function propose(Request $request, string $meeting): JsonResponse
    {
        $viewer = $this->viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'max:64'],
            'content' => ['nullable', 'string', 'max:'.$this->maxChars()],
        ]);
        $text = trim((string) ($data['content'] ?? $this->pad->draft($viewer)['content']));
        if ($text === '') {
            return response()->json(['message' => 'The scratchpad is empty, so there is nothing to work on.'], 422);
        }

        try {
            $proposal = $this->pad->propose($viewer, (string) $data['action'], $text);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['proposal' => $proposal]]);
    }

    /** Every pad the signed-in person has not filed or discarded, named by the product (PadContext). */
    public function pending(Request $request): JsonResponse
    {
        $class = config('calendar-client.scratchpad.pad_context');
        $context = is_string($class) && $class !== '' ? app($class) : null;
        if (! $context instanceof PadContext) {
            throw new RuntimeException('calendar-client: set calendar-client.scratchpad.pad_context to the product\'s PadContext.');
        }
        $author = $context->authorFor($request);
        if ($author === null) {
            return response()->json(['data' => []]);
        }

        $pads = [];
        foreach (app(CalendarClient::class)->pendingDrafts($author) as $pad) {
            $where = $context->describe($pad);
            if ($where !== null) {
                $pads[] = $where + ['content' => (string) $pad['content'], 'updated_at' => $pad['updated_at'] ?? null,
                    'start_utc' => $pad['booking']['start_utc'] ?? null];
            }
        }

        return response()->json(['data' => $pads]);
    }

    private function viewer(Request $request, string $meeting): ?ScratchpadViewer
    {
        return KitAccess::viewer($request, $meeting);
    }

    private function maxChars(): int
    {
        return (int) config('calendar-client.scratchpad.max_chars', 60000);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Meeting not found.'], 404);
    }
}
