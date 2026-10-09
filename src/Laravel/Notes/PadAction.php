<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

/**
 * Something a product offers on the scratchpad's TEXT ("Clean up with Porter"). It only PROPOSES:
 * the person sees the proposal beside their own text and accepts, edits or discards it (owner
 * 2026-10-09), so nothing they wrote is replaced without a click. Listed in config
 * `calendar-client.scratchpad.pad_actions`.
 */
interface PadAction
{
    public function key(): string;

    public function label(ScratchpadViewer $viewer): string;

    public function availableFor(ScratchpadViewer $viewer): bool;

    /** The proposed text. Throw to refuse: the message is shown. */
    public function propose(ScratchpadViewer $viewer, string $text): string;
}
