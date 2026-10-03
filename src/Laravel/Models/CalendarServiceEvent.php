<?php

namespace Shirahcan\CalendarClient\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A signed event calendar-service delivered to this product, one row per `event_id` (K4). The
 * table is created by the kit's migration, and is byte-for-byte the table Portify and MployNow
 * created themselves, so adopting the kit changes no schema.
 */
class CalendarServiceEvent extends Model
{
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
}
