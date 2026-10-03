<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calendar-client kit (K4): the signed events calendar-service delivered, one row per event_id.
 * Portify and MployNow created this exact table themselves first, so this is a no-op there.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_service_events')) {
            return;
        }

        Schema::create('calendar_service_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('event_type', 64)->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never dropped by the kit: a product that created it first owns it.
    }
};
