<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Shirahcan\CalendarClient\CalendarClient;

/**
 * A meeting's filed notes, the same in every product (plan N2). They live on its booking in
 * calendar-service. A person reads the shared notes and their own private ones; only the author
 * edits or deletes a note. Products add their own per-note actions (NoteAction).
 */
class MeetingNotes
{
    public function __construct(private readonly Container $app) {}

    /** @return list<array<string, mixed>> each note with `mine`, `private`, `author_name` and its `actions` */
    public function list(ScratchpadViewer $viewer): array
    {
        $notes = $this->client()->notes($viewer->booking(), $viewer->userId);
        $names = $this->names(array_values(array_unique(array_column($notes, 'author_auth_id'))));

        return array_map(fn (array $n) => $this->present($viewer, $n, $names), $notes);
    }

    public function add(ScratchpadViewer $viewer, string $content, bool $private = false): array
    {
        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('A note needs some text.');
        }
        $n = $this->client()->addNote($viewer->booking(), $viewer->userId, $content, $private ? 'private' : 'participants');

        return $this->present($viewer, $n, $this->names([$viewer->userId]));
    }

    /** @param array{content?: string, private?: bool} $fields */
    public function update(ScratchpadViewer $viewer, string $noteId, array $fields): array
    {
        $this->own($viewer, $noteId);
        $change = [];
        if (array_key_exists('content', $fields)) {
            if (trim((string) $fields['content']) === '') {
                throw new InvalidArgumentException('A note needs some text.');
            }
            $change['content'] = trim((string) $fields['content']);
        }
        if (array_key_exists('private', $fields)) {
            $change['visibility'] = $fields['private'] ? 'private' : 'participants';
        }
        $n = $this->client()->updateNote($noteId, $change);

        return $this->present($viewer, $n, $this->names([$viewer->userId]));
    }

    public function delete(ScratchpadViewer $viewer, string $noteId): void
    {
        $this->own($viewer, $noteId);
        $this->client()->deleteNote($noteId);
    }

    /** @return array{message: string, note: array<string, mixed>} */
    public function run(ScratchpadViewer $viewer, string $noteId, string $actionKey): array
    {
        $note = $this->find($viewer, $noteId);
        $action = collect($this->actions())->first(fn (NoteAction $a) => $a->key() === $actionKey && $a->availableFor($viewer, $note))
            ?? throw new InvalidArgumentException('That is not something this note offers.');
        $result = $action->run($viewer, $note);
        if (! empty($result['meta']) && is_array($result['meta'])) {
            $note = $this->client()->updateNote($noteId, ['meta' => $result['meta']]);
        }

        return [
            'message' => (string) ($result['message'] ?? 'Done.'),
            'note' => $this->present($viewer, $note, $this->names([(string) $note['author_auth_id']])),
        ];
    }

    private function present(ScratchpadViewer $viewer, array $n, array $names): array
    {
        return $n + [
            'mine' => $n['author_auth_id'] === $viewer->userId,
            'private' => ($n['visibility'] ?? '') === 'private',
            'author_name' => $names[$n['author_auth_id']] ?? null,
            'actions' => array_values(array_map(
                fn (NoteAction $a) => ['key' => $a->key(), 'label' => $a->label($viewer, $n)],
                array_filter($this->actions(), fn (NoteAction $a) => $a->availableFor($viewer, $n)),
            )),
        ];
    }

    private function find(ScratchpadViewer $viewer, string $noteId): array
    {
        return collect($this->client()->notes($viewer->booking(), $viewer->userId))->firstWhere('id', $noteId)
            ?? throw new InvalidArgumentException('That note is not on this meeting.');
    }

    private function own(ScratchpadViewer $viewer, string $noteId): void
    {
        if ($this->find($viewer, $noteId)['author_auth_id'] !== $viewer->userId) {
            throw new InvalidArgumentException('Only the person who wrote a note can change it.');
        }
    }

    /** @return array<int, NoteAction> */
    private function actions(): array
    {
        return array_map(function ($class) {
            $a = $this->app->make($class);
            if (! $a instanceof NoteAction) {
                throw new InvalidArgumentException("{$class} is not a NoteAction.");
            }

            return $a;
        }, (array) config('calendar-client.scratchpad.note_actions', []));
    }

    /** @return array<string, string> */
    private function names(array $authIds): array
    {
        $class = config('calendar-client.scratchpad.people');
        if (! is_string($class) || $class === '' || $authIds === []) {
            return [];
        }
        $dir = $this->app->make($class);

        return $dir instanceof PeopleDirectory ? $dir->names($authIds) : [];
    }

    private function client(): CalendarClient
    {
        return $this->app->make(CalendarClient::class);
    }
}
