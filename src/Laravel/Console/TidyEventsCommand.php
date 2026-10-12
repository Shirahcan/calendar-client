<?php

namespace Shirahcan\CalendarClient\Laravel\Console;

use Illuminate\Console\Command;
use Shirahcan\CalendarClient\Laravel\Models\CalendarServiceEvent;

/**
 * The event inbox keeps no copy of a booking (0.33.0): processed rows are slimmed to their ids and
 * rows older than the retention go. Products schedule it daily; safe to run any number of times.
 */
class TidyEventsCommand extends Command
{
    protected $signature = 'calendar-client:tidy-events';

    protected $description = 'Slim processed calendar-service events to their ids and delete events past the retention.';

    public function handle(): int
    {
        $slimmed = CalendarServiceEvent::slimProcessed();
        $pruned = (new CalendarServiceEvent())->prunable()->delete();

        $this->info("Slimmed {$slimmed}, deleted {$pruned}.");

        return self::SUCCESS;
    }
}
