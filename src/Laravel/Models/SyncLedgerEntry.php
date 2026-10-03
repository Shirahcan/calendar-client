<?php

namespace Shirahcan\CalendarClient\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What this product last sent calendar-service for one subject (a person, a team purpose, a
 * link), K5. One row per (subject_type, subject_id). `payload_hash` makes an unchanged subject
 * cost no call; `problem` is why the product REFUSED to send it (e.g. mixed zones); `last_error`
 * is why the service could not take it; `notes` are differences worth explaining (mixed lengths).
 */
class SyncLedgerEntry extends Model
{
    protected $table = 'calendar_service_syncs';

    protected $fillable = ['subject_type', 'subject_id', 'payload_hash', 'problem', 'notes', 'last_error', 'synced_at'];

    protected $casts = [
        'notes' => 'array',
        'synced_at' => 'datetime',
    ];

    public static function for(string $type, string $id): self
    {
        return static::firstOrNew(['subject_type' => $type, 'subject_id' => $id]);
    }
}
