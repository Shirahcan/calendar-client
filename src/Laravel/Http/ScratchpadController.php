<?php

namespace Shirahcan\CalendarClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use RuntimeException;
use Shirahcan\CalendarClient\Laravel\Notes\Scratchpad;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadAccess;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadTarget;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadViewer;

/**
 * The scratchpad's HTTP surface, the same in every product (CalendarKit::scratchpadRoutes):
 *
 *   GET  {prefix}/{meeting}/scratchpad          the person's pad + where it can be saved
 *   PUT  {prefix}/{meeting}/scratchpad          autosave { content }
 *   POST {prefix}/{meeting}/scratchpad/commit   file it { target, content? } and empty the pad
 *
 * Who may use it is the product's ScratchpadAccess. Anyone else gets 404, like a meeting that
 * does not exist.
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
            'content' => (string) ($draft?->content ?? ''),
            'updated_at' => $draft?->updated_at?->toIso8601String(),
            'targets' => $this->describe($viewer),
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

        return response()->json(['data' => ['updated_at' => $draft->updated_at?->toIso8601String()]]);
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
        $text = trim((string) ($data['content'] ?? $this->pad->draft($viewer)?->content ?? ''));
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

    /** @return array<int, array{key: string, label: string}> */
    private function describe(ScratchpadViewer $viewer): array
    {
        return array_map(
            fn (ScratchpadTarget $t) => ['key' => $t->key(), 'label' => $t->label($viewer)],
            $this->pad->targets($viewer),
        );
    }

    private function viewer(Request $request, string $meeting): ?ScratchpadViewer
    {
        $class = config('calendar-client.scratchpad.access');
        if (! is_string($class) || $class === '') {
            // Fail loudly: a product that mounted the routes without saying who may use them.
            throw new RuntimeException('calendar-client: set calendar-client.scratchpad.access to the product\'s ScratchpadAccess.');
        }

        $access = app($class);
        if (! $access instanceof ScratchpadAccess) {
            throw new RuntimeException("calendar-client: {$class} is not a ScratchpadAccess.");
        }

        return $access->viewer($request, $meeting);
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
