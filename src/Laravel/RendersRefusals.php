<?php

namespace Shirahcan\CalendarClient\Laravel;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

/**
 * One line in a product's bootstrap/app.php (K3):
 *
 *     ->withExceptions(function (Exceptions $exceptions) {
 *         \Shirahcan\CalendarClient\Laravel\RendersRefusals::register($exceptions);
 *     })
 *
 * A Refusal on any API request is answered as `{success: false, message}` with its own status
 * (409 taken, 503 unreachable, 422 rejected), never a 500, and is not reported a second time (a
 * real fault behind it was reported when the refusal was made).
 *
 * An OUTAGE on a read (a page listing meetings while calendar-service is down or failing) is
 * answered as a plain 503 the product's error surface can show, never a raw 500 with the
 * transport's text. It is still reported (HeldBookings reports it once per request).
 *
 * ⚠ A controller that wraps a booking write in its own `catch (\Throwable)` must let a Refusal
 * through (`if ($e instanceof Refusal) throw $e;`), or this never sees it.
 */
final class RendersRefusals
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReport(Refusal::class);
        $exceptions->render(function (Refusal $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status());
        });
        $exceptions->render(function (\Shirahcan\CalendarClient\Exceptions\CalendarServiceUnavailable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'The calendar is unavailable right now. Your meetings are safe; please try again in a few minutes.',
                'code' => 'calendar_unavailable',
            ], 503);
        });
    }
}
