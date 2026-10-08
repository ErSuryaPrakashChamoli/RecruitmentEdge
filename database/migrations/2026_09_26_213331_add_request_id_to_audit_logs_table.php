<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4: every audit row carries the correlation id of the request (or queued job) that wrote
 * it, so a sequence of identity changes — a separation, the revocation it caused, the handoff —
 * can be followed end to end. Additive; old rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('request_id', 64)->nullable()->after('ip_address');
            $table->index('request_id', 'audit_logs_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_request_id');
            $table->dropColumn('request_id');
        });
    }
};
