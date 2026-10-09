<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

/**
 * Something a product offers on ONE filed note, shown in the note's menu ("Convert to case note").
 * A product lists its actions in config `calendar-client.scratchpad.note_actions`.
 *
 * `$note` is the service's note: {id, booking_id, author_auth_id, content, visibility, meta, ...}.
 */
interface NoteAction
{
    public function key(): string;

    public function label(ScratchpadViewer $viewer, array $note): string;

    public function availableFor(ScratchpadViewer $viewer, array $note): bool;

    /**
     * Do it. Return a short message for the person, and optionally `meta` to remember on the
     * note (e.g. the case note it became). Throw to refuse: the message is shown.
     *
     * @return array{message: string, meta?: array<string, mixed>}
     */
    public function run(ScratchpadViewer $viewer, array $note): array;
}
