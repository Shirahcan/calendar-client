<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calendar-client kit (K5): the sync ledger, one row per (subject_type, subject_id). MployNow
 * created this exact table first (a no-op there). Portify's older table is keyed by consultant_id;
 * it adopts the general columns additively when it moves onto the kit (K7). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_service_syncs')) {
            return;
        }

        Schema::create('calendar_service_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 16);
            $table->string('subject_id', 64);
            $table->string('payload_hash', 64)->nullable();
            $table->string('problem', 255)->nullable();
            $table->json('notes')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        // Never dropped by the kit: a product that created it first owns it.
    }
};
