<?php

namespace Shirahcan\CalendarClient\Laravel\Notes;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One person's live scratchpad for one meeting (unique on the pair). */
class ScratchpadDraft extends Model
{
    use HasUuids;

    protected $table = 'meeting_scratchpad_drafts';

    protected $fillable = ['meeting_id', 'user_id', 'content'];
}
