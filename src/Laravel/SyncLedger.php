<?php

namespace Shirahcan\CalendarClient\Laravel;

use Shirahcan\CalendarClient\CalendarClient;
use Shirahcan\CalendarClient\Exceptions\CalendarServiceException;
use Shirahcan\CalendarClient\Laravel\Models\SyncLedgerEntry;

/**
 * Keeps calendar-service in step with a product's data, one subject at a time (K5): send only
 * what changed, record why something was not sent, never throw into whatever saved the row.
 *
 *     SyncLedger::sync('person', $id, $payload, $notes, function (CalendarClient $c) use (...) {
 *         $c->upsertHost(...); $c->upsertSchedule(...); $c->upsertBookingType(...);
 *     });
 *
 * `$payload` is everything the closure sends (it is hashed, so the same payload costs no call).
 * A product with something it must not send (a spec it cannot express) records it with refuse().
 */
final class SyncLedger
{
    public const SYNCED = 'synced';
    public const UNCHANGED = 'unchanged';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    /** @param callable(CalendarClient): void $send */
    public static function sync(string $type, string $id, array $payload, array $notes, callable $send, bool $force = false): string
    {
        $entry = SyncLedgerEntry::for($type, $id);
        $hash = hash('sha256', json_encode($payload));

        if (! $force && $entry->exists && $entry->payload_hash === $hash && $entry->problem === null && $entry->last_error === null) {
            if (($entry->notes ?? []) !== $notes) {
                $entry->update(['notes' => $notes ?: null]);
            }

            return self::UNCHANGED;
        }

        try {
            $send(app(CalendarClient::class));
        } catch (CalendarServiceException $e) {
            report($e);
            $entry->fill(['last_error' => mb_substr($e->getMessage(), 0, 500), 'notes' => $notes ?: null])->save();

            return self::FAILED;
        }

        $entry->fill(['payload_hash' => $hash, 'problem' => null, 'notes' => $notes ?: null, 'last_error' => null, 'synced_at' => now()])->save();

        return self::SYNCED;
    }

    /** Why this subject was NOT sent (visible on the ledger; the next real change retries). */
    public static function refuse(string $type, string $id, array $problems, array $notes = []): string
    {
        SyncLedgerEntry::for($type, $id)->fill([
            'problem' => mb_substr(implode('; ', $problems), 0, 255),
            'notes' => $notes ?: null,
            'payload_hash' => null,
        ])->save();

        return self::REFUSED;
    }

    /** The service took this subject's last change (and nothing was refused since). */
    public static function isCurrent(string $type, string $id): bool
    {
        $entry = SyncLedgerEntry::where('subject_type', $type)->where('subject_id', $id)->first();

        return $entry !== null && $entry->synced_at !== null && $entry->problem === null && $entry->last_error === null;
    }
}
