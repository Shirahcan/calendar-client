<?php

namespace Shirahcan\CalendarClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Shirahcan\CalendarClient\Laravel\Notes\MeetingNotes;

/**
 * A meeting's notes over HTTP, the same in every product (CalendarKit::meetingNotesRoutes):
 *
 *   GET    {prefix}/{meeting}/notes                          what the person may read
 *   POST   {prefix}/{meeting}/notes                          { content, private? }
 *   PATCH  {prefix}/{meeting}/notes/{note}                   { content?, private? } (author only)
 *   DELETE {prefix}/{meeting}/notes/{note}                   (author only)
 *   POST   {prefix}/{meeting}/notes/{note}/actions/{action}  a product's NoteAction
 *
 * Same access as the scratchpad (the product's ScratchpadAccess): anyone else gets 404.
 */
class MeetingNotesController extends Controller
{
    public function __construct(private readonly MeetingNotes $notes) {}

    public function index(Request $request, string $meeting): JsonResponse
    {
        $viewer = KitAccess::viewer($request, $meeting);

        return $viewer === null ? $this->notFound() : response()->json(['data' => $this->notes->list($viewer)]);
    }

    public function store(Request $request, string $meeting): JsonResponse
    {
        $viewer = KitAccess::viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }
        $data = $request->validate(['content' => ['required', 'string', 'max:20000'], 'private' => ['sometimes', 'boolean']]);

        return $this->attempt(fn () => response()->json(['data' => $this->notes->add($viewer, $data['content'], (bool) ($data['private'] ?? false))], 201));
    }

    public function update(Request $request, string $meeting, string $note): JsonResponse
    {
        $viewer = KitAccess::viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }
        $data = $request->validate(['content' => ['sometimes', 'string', 'max:20000'], 'private' => ['sometimes', 'boolean']]);

        return $this->attempt(fn () => response()->json(['data' => $this->notes->update($viewer, $note, $data)]));
    }

    public function destroy(Request $request, string $meeting, string $note): JsonResponse
    {
        $viewer = KitAccess::viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }

        return $this->attempt(function () use ($viewer, $note) {
            $this->notes->delete($viewer, $note);

            return response()->json(['data' => ['deleted' => true]]);
        });
    }

    public function action(Request $request, string $meeting, string $note, string $action): JsonResponse
    {
        $viewer = KitAccess::viewer($request, $meeting);
        if ($viewer === null) {
            return $this->notFound();
        }

        return $this->attempt(fn () => response()->json(['data' => $this->notes->run($viewer, $note, $action)]));
    }

    /** A refusal the person can act on is a 422 with its message; anything else is the product's error. */
    private function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Meeting not found.'], 404);
    }
}
