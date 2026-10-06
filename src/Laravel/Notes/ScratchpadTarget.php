<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

/**
 * Somewhere the scratchpad can be filed ("Save to case notes", "Save to interview notes"), as
 * each product decides (owner 2026-10-07: "save to case" is customizable per product). A product
 * lists its targets in config `calendar-client.scratchpad.targets`; the pad offers every one that
 * is available for this person on this meeting, in that order.
 */
interface ScratchpadTarget
{
    /** Stable id the browser sends back ("meeting_notes"). */
    public function key(): string;

    /** The button: "Save to case notes". */
    public function label(ScratchpadViewer $viewer): string;

    public function availableFor(ScratchpadViewer $viewer): bool;

    /** File the text. Throw to refuse; the draft is kept when this throws. */
    public function save(ScratchpadViewer $viewer, string $text): void;
}
