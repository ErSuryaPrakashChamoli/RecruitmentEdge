<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4: a proposed AI action keeps who asked for it (immutable), when it expires, and a
 * fingerprint of the requester's authority when it was proposed; it can be invalidated with a
 * reason. Backfill, deterministic: requested_by = the conversation's owner; a pending action's
 * expires_at = created_at + 30 minutes (so old pending actions are expired, never approvable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table): void {
            $table->foreignId('requested_by')->nullable()->after('requires_confirmation')->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->after('requested_by');
            $table->string('authority_fingerprint', 64)->nullable()->after('expires_at');
            $table->timestamp('invalidated_at')->nullable()->after('executed_at');
            $table->string('invalidation_reason', 100)->nullable()->after('invalidated_at');

            $table->index(['status', 'expires_at'], 'ai_tool_calls_status_expiry');
        });

        DB::table('ai_tool_calls')
            ->whereNull('requested_by')
            ->update(['requested_by' => DB::raw('(select ai_conversations.user_id from ai_messages join ai_conversations on ai_conversations.id = ai_messages.conversation_id where ai_messages.id = ai_tool_calls.message_id)')]);

        DB::table('ai_tool_calls')->where('status', 'pending')->whereNull('expires_at')->orderBy('id')->each(function (object $call): void {
            DB::table('ai_tool_calls')->where('id', $call->id)->update(['expires_at' => date('Y-m-d H:i:s', strtotime((string) $call->created_at) + 30 * 60)]);
        });
    }

    public function down(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table): void {
            $table->dropIndex('ai_tool_calls_status_expiry');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['expires_at', 'authority_fingerprint', 'invalidated_at', 'invalidation_reason']);
        });
    }
};
