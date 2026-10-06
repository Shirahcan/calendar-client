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
    | The scratchpad and meeting notes kit (Laravel\Notes). Stored in THIS product's database.
    | `access`: the product's ScratchpadAccess (who may use a meeting's pad, and as whom).
    | `targets`: where the pad can be filed, in the order offered ("save to case" is the
    | product's own wording). `note_model`: the product's model for meeting_notes, if it has one.
    */
    'scratchpad' => [
        'access' => null,
        'targets' => [\Shirahcan\CalendarClient\Laravel\Notes\MeetingNotesTarget::class],
        'note_model' => \Shirahcan\CalendarClient\Laravel\Notes\MeetingNote::class,
        'max_chars' => 60000,
    ],

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
