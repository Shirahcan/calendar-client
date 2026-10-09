<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Illuminate\Http\Request;

/**
 * Where an unsettled pad belongs in the PRODUCT, for the banners that surface it (plan N4): who is
 * asking, and for each pad the meeting it is on, its title and where to open it. The kit lists
 * pads from the service; the product names them. Config `calendar-client.scratchpad.pad_context`.
 */
interface PadContext
{
    /** The signed-in person's auth id, or null (no pads). */
    public function authorFor(Request $request): ?string;

    /**
     * @param array<string, mixed> $pad the service's pad, with its `booking`
     * @return array{meeting_id: string, title: string, href: string, case_id?: ?string}|null null hides it
     */
    public function describe(array $pad): ?array;
}
