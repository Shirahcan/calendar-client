<?php

namespace Shirahcan\CalendarClient\Laravel\Http;

use Illuminate\Http\Request;
use RuntimeException;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadAccess;
use Shirahcan\CalendarClient\Laravel\Notes\ScratchpadViewer;

/** The product's ScratchpadAccess, resolved once for the pad and the notes routes. */
final class KitAccess
{
    public static function viewer(Request $request, string $meeting): ?ScratchpadViewer
    {
        $class = config('calendar-client.scratchpad.access');
        if (! is_string($class) || $class === '') {
            // Fail loudly: a product that mounted the routes without saying who may use them.
            throw new RuntimeException('calendar-client: set calendar-client.scratchpad.access to the product\'s ScratchpadAccess.');
        }

        $access = app($class);
        if (! $access instanceof ScratchpadAccess) {
            throw new RuntimeException("calendar-client: {$class} is not a ScratchpadAccess.");
        }

        return $access->viewer($request, $meeting);
    }
}
