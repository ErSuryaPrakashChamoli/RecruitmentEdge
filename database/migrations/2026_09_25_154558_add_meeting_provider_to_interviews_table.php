<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which video provider hosts an interview's meeting and its external meeting id (Phase 5).
     * The join URL stays in the existing `meeting_link`. Provider credentials are never stored on
     * interviews — only these public identifiers.
     */
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->string('meeting_provider', 30)->nullable()->after('meeting_link');
            $table->string('external_meeting_id')->nullable()->after('meeting_provider');
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn(['meeting_provider', 'external_meeting_id']);
        });
    }
};
