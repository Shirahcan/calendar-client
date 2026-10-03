<?php

namespace Shirahcan\CalendarClient\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
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
}
