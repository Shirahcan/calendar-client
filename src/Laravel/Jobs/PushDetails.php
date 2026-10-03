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
 * Send a service-owned booking's calendar copy (title, description, location) and who receives it,
 * as the product's subject describes it (K6). Text written for one person goes to that person only.
 */
class PushDetails implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @param class-string<BookingSubject> $subject */
    public function __construct(public readonly string $subject, public readonly int|string $key) {}

    public function uniqueId(): string
    {
        return $this->subject.'#'.$this->key;
    }

    public function backoff(): array
    {
        return [30, 300];
    }

    /** ⚠ Not `queue()`: Laravel's dispatcher calls a job's own queue() instead of pushing it. */
    public static function enqueue(string $subject, int|string $key): void
    {
        try {
            static::dispatch($subject, $key)->delay(now()->addSeconds(10))->afterCommit();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function handle(): void
    {
        /** @var BookingSubject $s */
        $s = app($this->subject);
        $m = ($s->modelClass())::query()->find($this->key);
        if (! $s->enabled() || $m === null || ! ($id = $m->getAttribute($s->column())) || ($d = $s->describe($m)) === null) {
            return;
        }

        try {
            (new Seam())->describe($id, $d['title'] ?? null, $d['description'] ?? null, $d['location'] ?? null, array_key_exists('write_to', $d) ? $d['write_to'] : false);
        } catch (Refusal $e) {
            if ($e->cause instanceof CalendarNotFound) {
                return;   // the booking is gone; nothing to describe
            }
            throw $e;
        }
    }
}
