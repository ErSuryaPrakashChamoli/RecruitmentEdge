<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.1: marks which AI privacy boundary a conversation was created under. Existing rows stay
 * NULL — legacy, read-only historical records that are never rewritten. New conversations are
 * stamped by the model. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('privacy_version')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropColumn('privacy_version');
        });
    }
};
