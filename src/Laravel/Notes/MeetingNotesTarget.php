<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

/**
 * The built-in target: file the pad as a note on the meeting itself. A product changes the
 * wording by extending this and overriding label(), or replaces it with targets of its own.
 */
class MeetingNotesTarget implements ScratchpadTarget
{
    public function key(): string
    {
        return 'meeting_notes';
    }

    public function label(ScratchpadViewer $viewer): string
    {
        return 'Save to meeting notes';
    }

    public function availableFor(ScratchpadViewer $viewer): bool
    {
        return true;
    }

    public function save(ScratchpadViewer $viewer, string $text): void
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = config('calendar-client.scratchpad.note_model', MeetingNote::class);

        $model::create([
            'meeting_id' => $viewer->meetingId,
            'user_id' => $viewer->userId,
            'content' => $text,
            'is_private' => false,
        ]);
    }
}
