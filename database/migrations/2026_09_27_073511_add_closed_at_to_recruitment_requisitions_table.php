<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.5 (requisition ageing): when a requisition was closed or cancelled, so its age stops
 * there. Set by RequisitionApprovalService going forward; requisitions closed before Phase 8.5 keep
 * null ("closed, date unknown") — history is never back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requisitions', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_requisitions', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
