<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table) {
            // Opaque, provider-specific round-trip data a tool call may carry — e.g. Gemini's
            // thought_signature, which must be replayed verbatim on the next turn or Gemini 3
            // models return a 400. Deliberately generic (not "gemini_thought_signature") so any
            // future provider with similar stateful-reasoning requirements can reuse the column.
            $table->json('provider_metadata')->nullable()->after('arguments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table) {
            $table->dropColumn('provider_metadata');
        });
    }
};
