<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.9 (P89-PERF-008): the daily-limit count — "runs of this rule started today" — now runs
 * when a run is due as well as when it runs. Without this index it used only the rule prefix of
 * automation_exec_rule_created and read the rule's whole history on every check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_executions', function (Blueprint $table): void {
            $table->index(['automation_rule_id', 'started_at'], 'automation_exec_rule_started');
        });
    }

    public function down(): void
    {
        Schema::table('automation_executions', function (Blueprint $table): void {
            $table->dropIndex('automation_exec_rule_started');
        });
    }
};
