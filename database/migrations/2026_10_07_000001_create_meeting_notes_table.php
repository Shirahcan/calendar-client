<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calendar-client kit (notes): notes filed against a meeting, in the PRODUCT's own database.
 * Notes carry client content, so no shared service stores them (owner 2026-10-07, plan
 * shared-meetings P03b option A). Portify created this exact table first, so this is a no-op
 * there. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meeting_notes')) {
            return;
        }

        Schema::create('meeting_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('meeting_id', 64)->index();
            $table->string('user_id', 64);
            $table->text('content');
            $table->boolean('is_private')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Never dropped by the kit: a product that created it first owns it.
    }
};
