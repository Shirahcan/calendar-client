<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Shirahcan\CalendarClient\CalendarClient;

/**
 * The built-in target: file the pad as a note on the meeting itself (its booking, plan N1). A
 * product changes the wording by extending this and overriding label(), or adds targets of its own.
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
        return $viewer->bookingId !== null;
    }

    public function save(ScratchpadViewer $viewer, string $text): void
    {
        app(CalendarClient::class)->addNote($viewer->booking(), $viewer->userId, $text, 'participants');
    }
}
