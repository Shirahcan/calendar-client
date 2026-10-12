<?php

namespace Shirahcan\CalendarClient\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A signed event calendar-service delivered to this product, one row per `event_id` (K4). The
 * table is created by the kit's migration, and is byte-for-byte the table Portify and MployNow
 * created themselves, so adopting the kit changes no schema.
 *
 * ⚠ THE INBOX IS NOT A COPY OF THE BOOKING. An event arrives carrying the whole booking (people's
 * names, emails and zones, the title, the times) so the product can act on it. Once acted on, the
 * row keeps only what deduplication and an audit need: the event, its id and the booking's ids
 * (markProcessed). Rows go after RETENTION_DAYS (Prunable), and an erased booking takes its rows
 * with it (forgetBooking). Before 0.33.0 every snapshot stayed for ever, outside every erasure.
 */
class CalendarServiceEvent extends Model
{
    use Prunable;

    /** How long a row (slim once processed) is kept: long enough to answer "did this arrive?". */
    public const RETENTION_DAYS = 30;

    protected $table = 'calendar_service_events';

    protected $fillable = ['event_id', 'event_type', 'payload', 'processed_at'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /** The service booking the event is about, when it is about one. */
    public function bookingId(): ?string
    {
        $id = $this->payload['booking']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The product's own reference for that booking (`product_ref`), when it set one. */
    public function productRef(): ?string
    {
        $ref = $this->payload['booking']['product_ref'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /**
     * The product has acted on this event: stamp it and keep only the ids. Idempotent.
     */
    public function markProcessed(): void
    {
        $this->forceFill([
            'processed_at' => $this->processed_at ?? now(),
            'payload' => self::slim((array) $this->payload),
        ])->save();
    }

    /** What a processed row keeps: the event, its id, the booking's ids, a reminder's offset. */
    public static function slim(array $payload): array
    {
        $booking = (array) ($payload['booking'] ?? []);

        return array_filter([
            'event' => $payload['event'] ?? null,
            'event_id' => $payload['event_id'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? null,
            'booking' => array_filter([
                'id' => $booking['id'] ?? null,
                'product_ref' => $booking['product_ref'] ?? null,
            ], fn ($v) => $v !== null) ?: null,
            'reminder' => isset($payload['reminder']['offset_minutes'])
                ? ['offset_minutes' => $payload['reminder']['offset_minutes']]
                : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Slim every processed row that still carries a full snapshot (rows from before 0.33.0, or a
     * product that stamps `processed_at` itself). Returns how many changed.
     */
    public static function slimProcessed(int $chunk = 500): int
    {
        $changed = 0;
        static::query()->whereNotNull('processed_at')->orderBy('id')->chunkById($chunk, function ($rows) use (&$changed) {
            foreach ($rows as $row) {
                $slim = self::slim((array) $row->payload);
                if ($slim !== (array) $row->payload) {
                    $row->forceFill(['payload' => $slim])->save();
                    $changed++;
                }
            }
        });

        return $changed;
    }

    /** The booking is gone (erased): every event about it goes too. Returns how many. */
    public static function forgetBooking(?string $bookingId): int
    {
        if ($bookingId === null || $bookingId === '') {
            return 0;
        }

        return static::query()->where('payload->booking->id', $bookingId)->delete();
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
