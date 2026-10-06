<?php

namespace Shirahcan\CalendarClient\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
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
        ];
    }
}
