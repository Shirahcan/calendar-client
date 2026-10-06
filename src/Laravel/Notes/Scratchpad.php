<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The scratchpad beside a call, the same in every product: one live draft per person per
 * meeting, autosaved, and filed to whichever targets the product offers.
 */
class Scratchpad
{
    public function __construct(private readonly Container $app) {}

    public function draft(ScratchpadViewer $viewer): ?ScratchpadDraft
    {
        return ScratchpadDraft::where('meeting_id', $viewer->meetingId)->where('user_id', $viewer->userId)->first();
    }

    public function saveDraft(ScratchpadViewer $viewer, string $content): ScratchpadDraft
    {
        return ScratchpadDraft::updateOrCreate(
            ['meeting_id' => $viewer->meetingId, 'user_id' => $viewer->userId],
            ['content' => $content],
        );
    }

    /** @return array<int, ScratchpadTarget> the targets this person may file to, in config order */
    public function targets(ScratchpadViewer $viewer): array
    {
        $targets = [];
        foreach ((array) config('calendar-client.scratchpad.targets', [MeetingNotesTarget::class]) as $class) {
            $target = $this->app->make($class);
            if (! $target instanceof ScratchpadTarget) {
                throw new InvalidArgumentException("{$class} is not a ScratchpadTarget.");
            }
            if ($target->availableFor($viewer)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * File the text to one target, then empty the draft. One transaction: a target that throws
     * leaves the draft exactly as it was, so nothing the person wrote is lost.
     */
    public function commit(ScratchpadViewer $viewer, string $targetKey, string $text): ScratchpadTarget
    {
        $target = collect($this->targets($viewer))->first(fn (ScratchpadTarget $t) => $t->key() === $targetKey);
        if ($target === null) {
            throw new InvalidArgumentException('That is not somewhere this pad can be saved.');
        }

        DB::transaction(function () use ($viewer, $target, $text) {
            $target->save($viewer, $text);
            $this->saveDraft($viewer, '');
        });

        return $target;
    }
}
