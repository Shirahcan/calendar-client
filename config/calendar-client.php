<?php

return [

    /*
    | Loopback, always. Only OAuth callbacks and provider pushes are public on
    | calendar.shirah.co; the API is not.
    */
    'base_url' => env('CALENDAR_SERVICE_URL', 'http://127.0.0.1:8010'),

    /*
    | Issued BY the service (`php artisan calendar:issue-key <product>`): the callee owns
    | the key. This product never mints its own.
    */
    'trust_key' => env('CALENDAR_SERVICE_TRUST_KEY', ''),

    /* A backstop against a hung socket. Slot queries take tens of milliseconds. */
    'timeout' => (int) env('CALENDAR_SERVICE_TIMEOUT', 10),

    /*
    | The secret calendar-service signs this product's event callbacks with
    | (`calendar:set-callback` prints it once). Only the callback receiver needs it.
    */
    'callback_secret' => env('CALENDAR_SERVICE_CALLBACK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Deliberately absent
    |--------------------------------------------------------------------------
    |
    | ⚠ No Google or Microsoft client id, no calendar tokens, no slot engine. The moment
    | a product's config names one, the single source of truth has leaked back out.
    |
    */

];
