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
        Schema::table('ai_action_logs', function (Blueprint $table) {
            // The full ToolResult (success/data/summary/type/error) for the audited action —
            // result_summary alone loses the data an auditor needs to see what actually changed.
            $table->json('output')->nullable()->after('input');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_action_logs', function (Blueprint $table) {
            $table->dropColumn('output');
        });
    }
};
