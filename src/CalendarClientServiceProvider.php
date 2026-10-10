<?php

namespace Shirahcan\CalendarClient;

use Illuminate\Support\ServiceProvider;

class CalendarClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/calendar-client.php', 'calendar-client');

        // The bookings one request has read (HeldInCalendarService): never outlives the request.
        $this->app->scoped(\Shirahcan\CalendarClient\Laravel\Bookings\HeldBookings::class);
        $this->app->scoped(\Shirahcan\CalendarClient\Laravel\HostConnections::class);

        $this->app->singleton(CalendarClient::class, function () {
            $key = (string) config('calendar-client.trust_key', '');

            /*
             * ⚠ FAIL LOUDLY AT RESOLUTION, NOT SILENTLY AT THE FIRST CALL. An unset key is a
             * deployment mistake, far cheaper to find here than as a 401 while a client is
             * trying to book. A product that has not migrated yet never resolves this.
             */
            if ($key === '') {
                throw new \RuntimeException(
                    'calendar-client: CALENDAR_SERVICE_TRUST_KEY is not set. Issue one on the service '
                    .'with `php artisan calendar:issue-key <product>` and put it in this app\'s .env.'
                );
            }

            return new CalendarServiceClient(
                baseUrl: (string) config('calendar-client.base_url'),
                trustKey: $key,
                timeout: (int) config('calendar-client.timeout', 10),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/calendar-client.php' => config_path('calendar-client.php'),
        ], 'calendar-client-config');

        // The product-side kit's tables (K). Each migration is a no-op where the product already
        // created that table, so adopting the kit changes no schema.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
