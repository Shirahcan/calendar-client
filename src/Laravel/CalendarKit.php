<?php

namespace Shirahcan\CalendarClient\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Shirahcan\CalendarClient\Laravel\Http\MeetingNotesController;
use Shirahcan\CalendarClient\Laravel\Http\ScratchpadController;
use Shirahcan\CalendarClient\Laravel\Http\WebhookController;

/**
 * Entry points of the product-side kit (K), so every product wires calendar-service the same way.
 */
final class CalendarKit
{
    /**
     * The webhook receiver, as a route. Call it inside the product's API routes file, OUTSIDE any
     * auth middleware (the signature is the authentication):
     *
     *     \Shirahcan\CalendarClient\Laravel\CalendarKit::webhookRoute('/webhooks/calendar-service');
     */
    public static function webhookRoute(string $uri = '/webhooks/calendar-service'): Route
    {
        return Router::post($uri, WebhookController::class)->name('webhooks.calendar-service');
    }

    /**
     * The scratchpad beside a call (notes kit). Call it inside whichever route group gives the
     * product's ScratchpadAccess what it needs (a signed-in guard, or none for an emailed link the
     * access class checks itself):
     *
     *     CalendarKit::scratchpadRoutes('/v1/calendar/meetings');
     *
     * @return array<int, Route>
     */
    public static function scratchpadRoutes(string $prefix, string $name = 'scratchpad'): array
    {
        $prefix = rtrim($prefix, '/');

        return [
            Router::get($prefix.'/{meeting}/scratchpad', [ScratchpadController::class, 'show'])->name($name.'.show'),
            Router::put($prefix.'/{meeting}/scratchpad', [ScratchpadController::class, 'save'])->name($name.'.save'),
            Router::post($prefix.'/{meeting}/scratchpad/commit', [ScratchpadController::class, 'commit'])->name($name.'.commit'),
            Router::delete($prefix.'/{meeting}/scratchpad', [ScratchpadController::class, 'discard'])->name($name.'.discard'),
            Router::post($prefix.'/{meeting}/scratchpad/propose', [ScratchpadController::class, 'propose'])->name($name.'.propose'),
        ];
    }

    /**
     * Every pad the signed-in person has not settled (the banners, plan N4). Mount it where the
     * product's PadContext can tell who is asking:
     *
     *     CalendarKit::pendingPadsRoute('/v1/scratchpads/pending');
     */
    public static function pendingPadsRoute(string $uri, string $name = 'scratchpads.pending'): Route
    {
        return Router::get($uri, [ScratchpadController::class, 'pending'])->name($name);
    }

    /**
     * A meeting's notes (plan N2), with the same access as its scratchpad:
     *
     *     CalendarKit::meetingNotesRoutes('/v1/calendar/meetings');
     *
     * @return array<int, Route>
     */
    public static function meetingNotesRoutes(string $prefix, string $name = 'meeting-notes'): array
    {
        $prefix = rtrim($prefix, '/');

        return [
            Router::get($prefix.'/{meeting}/notes', [MeetingNotesController::class, 'index'])->name($name.'.index'),
            Router::post($prefix.'/{meeting}/notes', [MeetingNotesController::class, 'store'])->name($name.'.store'),
            Router::patch($prefix.'/{meeting}/notes/{note}', [MeetingNotesController::class, 'update'])->name($name.'.update'),
            Router::delete($prefix.'/{meeting}/notes/{note}', [MeetingNotesController::class, 'destroy'])->name($name.'.destroy'),
            Router::post($prefix.'/{meeting}/notes/{note}/actions/{action}', [MeetingNotesController::class, 'action'])->name($name.'.action'),
        ];
    }
}
