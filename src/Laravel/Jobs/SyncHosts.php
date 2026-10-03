<?php

namespace Shirahcan\CalendarClient\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Shirahcan\CalendarClient\Exceptions\CalendarNotFound;
use Shirahcan\CalendarClient\Laravel\Bookings\BookingSubject;
use Shirahcan\CalendarClient\Laravel\Refusal;
use Shirahcan\CalendarClient\Laravel\Seam;

/**
 * Somebody joined or left a service-owned booking (a participant added later, a new organizer):
 * the service is told, so that person's time is busy (or free) in every product (K6).
 */
class SyncHosts implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $uniqueFor = 300;

    /** @param class-string<BookingSubject> $subject */
    public function __construct(public readonly string $subject, public readonly int|string $key) {}

    public function uniqueId(): string
    {
        return $this->subject.'#'.$this->key;
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    /** ⚠ Not `queue()`: Laravel's dispatcher calls a job's own queue() instead of pushing it. */
    public static function enqueue(string $subject, int|string $key): void
    {
        try {
            static::dispatch($subject, $key)->delay(now()->addSeconds(5))->afterCommit();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function handle(): void
    {
        /** @var BookingSubject $s */
        $s = app($this->subject);
        $m = ($s->modelClass())::query()->find($this->key);
        if (! $s->enabled() || $m === null || ! ($id = $m->getAttribute($s->column())) || ! $s->occupies($m)) {
            return;
        }
        $hosts = $s->hosts($m);
        if ($hosts === []) {
            return;
        }

        try {
            (new Seam())->setHosts($id, $hosts, $s->checkBusyOnJoin(), $s->actor());
        } catch (Refusal $e) {
            if ($e->cause instanceof CalendarNotFound || $e->code() === 'invalid_state') {
                return;   // gone, or no longer live: nothing to keep busy
            }
            throw $e;
        }
    }
}
