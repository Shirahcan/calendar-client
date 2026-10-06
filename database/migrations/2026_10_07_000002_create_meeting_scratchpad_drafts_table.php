<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calendar-client kit (notes): the scratchpad beside a call, one live draft per person per
 * meeting, in the product's own database. Autosave is an upsert on the pair. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meeting_scratchpad_drafts')) {
            return;
        }

        Schema::create('meeting_scratchpad_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('meeting_id', 64);
            $table->string('user_id', 64);
            $table->longText('content')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id'], 'uniq_meeting_scratchpad_draft');
        });
    }

    public function down(): void
    {
        // Never dropped by the kit: it may hold a person's unsaved notes.
    }
};
