<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.7 (D8.7-014/015): who did something asynchronously, and which request started it.
 *
 * - audit_logs.actor_kind: user, candidate, automation, scheduler, console, queue, ai or system;
 *   on_behalf_of_user_id: the person whose authority an automation or AI action used (the rule
 *   owner, the requester) — never recorded as the actor itself. Added next to Phase 8.6's reason.
 * - automation_executions / candidate_communications.origin_request_id: the correlation id of
 *   the request, command or job that created the row, so a delayed run can be traced back.
 *
 * Additive and nullable; rows written before 8.7 keep nulls (not back-filled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('actor_kind', 20)->nullable()->after('actor_id');
            $table->foreignId('on_behalf_of_user_id')->nullable()->after('actor_kind');
            $table->foreign('on_behalf_of_user_id', 'audit_logs_on_behalf_of_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('automation_executions', function (Blueprint $table): void {
            $table->string('origin_request_id', 64)->nullable()->after('idempotency_key');
            $table->index('origin_request_id', 'automation_executions_origin_request');
        });

        Schema::table('candidate_communications', function (Blueprint $table): void {
            $table->string('origin_request_id', 64)->nullable()->after('idempotency_key');
            $table->index('origin_request_id', 'candidate_communications_origin_request');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_communications', function (Blueprint $table): void {
            $table->dropIndex('candidate_communications_origin_request');
            $table->dropColumn('origin_request_id');
        });

        Schema::table('automation_executions', function (Blueprint $table): void {
            $table->dropIndex('automation_executions_origin_request');
            $table->dropColumn('origin_request_id');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropForeign('audit_logs_on_behalf_of_fk');
            $table->dropColumn(['actor_kind', 'on_behalf_of_user_id']);
        });
    }
};
