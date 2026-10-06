<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Illuminate\Http\Request;

/**
 * The product's answer to "may this request use this meeting's scratchpad, and as whom".
 *
 * Signed in as a host of the meeting, or holding a host's emailed join link: the product knows
 * both, the kit knows neither. Null means no, and the kit answers it exactly like a meeting that
 * does not exist (404), so the pad never confirms a meeting to someone outside it.
 */
interface ScratchpadAccess
{
    public function viewer(Request $request, string $meetingId): ?ScratchpadViewer;
}
