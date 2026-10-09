<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Shirahcan\CalendarClient\CalendarClient;

/**
 * The scratchpad beside a call, the same in every product: one live pad per person per meeting,
 * autosaved, filed to whichever targets the product offers. It lives on the meeting's booking in
 * calendar-service (plan N1), so a pad left unsettled is still there wherever the person looks.
 */
class Scratchpad
{
    public function __construct(private readonly Container $app) {}

    /** @return array{content: string, updated_at: ?string} */
    public function draft(ScratchpadViewer $viewer): array
    {
        $d = $this->client()->draft($viewer->booking(), $viewer->userId);

        return ['content' => (string) ($d['content'] ?? ''), 'updated_at' => $d['updated_at'] ?? null];
    }

    /** @return array{content: string, updated_at: ?string} */
    public function saveDraft(ScratchpadViewer $viewer, string $content): array
    {
        $d = $this->client()->saveDraft($viewer->booking(), $viewer->userId, $content);

        return ['content' => (string) ($d['content'] ?? ''), 'updated_at' => $d['updated_at'] ?? null];
    }

    public function discard(ScratchpadViewer $viewer): void
    {
        $this->client()->discardDraft($viewer->booking(), $viewer->userId);
    }

    /** @return array<int, ScratchpadTarget> the targets this person may file to, in config order */
    public function targets(ScratchpadViewer $viewer): array
    {
        return array_values(array_filter(
            $this->make('targets', ScratchpadTarget::class, [MeetingNotesTarget::class]),
            fn (ScratchpadTarget $t) => $t->availableFor($viewer),
        ));
    }

    /** @return array<int, PadAction> */
    public function actions(ScratchpadViewer $viewer): array
    {
        return array_values(array_filter(
            $this->make('pad_actions', PadAction::class, []),
            fn (PadAction $a) => $a->availableFor($viewer),
        ));
    }

    /**
     * File the text to one target, then empty the pad. The target files first: if it throws the
     * pad is untouched, so nothing the person wrote is lost.
     */
    public function commit(ScratchpadViewer $viewer, string $targetKey, string $text): ScratchpadTarget
    {
        $target = collect($this->targets($viewer))->first(fn (ScratchpadTarget $t) => $t->key() === $targetKey)
            ?? throw new InvalidArgumentException('That is not somewhere this pad can be saved.');
        $target->save($viewer, $text);
        $this->saveDraft($viewer, '');

        return $target;
    }

    /** What a pad action proposes for the text (never applied here: the person decides). */
    public function propose(ScratchpadViewer $viewer, string $actionKey, string $text): string
    {
        $action = collect($this->actions($viewer))->first(fn (PadAction $a) => $a->key() === $actionKey)
            ?? throw new InvalidArgumentException('That is not something this pad offers.');

        return $action->propose($viewer, $text);
    }

    private function client(): CalendarClient
    {
        return $this->app->make(CalendarClient::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return array<int, T>
     */
    private function make(string $key, string $type, array $default): array
    {
        $out = [];
        foreach ((array) config("calendar-client.scratchpad.{$key}", $default) as $class) {
            $made = $this->app->make($class);
            if (! $made instanceof $type) {
                throw new InvalidArgumentException("{$class} is not a ".class_basename($type).'.');
            }
            $out[] = $made;
        }

        return $out;
    }
}
