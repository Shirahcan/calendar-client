<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * LEGACY (plan N1): notes now live on the booking in calendar-service. Kept only so a product can
 * read its old rows to move them; the kit no longer reads or writes this table.
 *
 * A note filed against a meeting. A product with its own model for this table (Portify's
 * App\Models\MeetingNote, which has observers) names it in config
 * `calendar-client.scratchpad.note_model`, and the kit writes through that instead.
 */
class MeetingNote extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'meeting_notes';

    protected $fillable = ['meeting_id', 'user_id', 'content', 'is_private'];

    protected $casts = ['is_private' => 'boolean'];
}
