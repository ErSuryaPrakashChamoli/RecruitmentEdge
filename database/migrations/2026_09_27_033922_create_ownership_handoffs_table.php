<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4: when someone loses access (suspended, revoked, separated), their current work is
 * handed off. One row per loss of access (dedupe_key): who left, who is now responsible
 * (assignee), what was open (counts only) and whether the handoff is complete. Historical owner
 * columns on applications, requisitions and interviews are never rewritten by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ownership_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->string('dedupe_key', 120)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('trigger', 30);
            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->json('summary')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'assignee_employee_id'], 'ownership_handoffs_status_assignee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ownership_handoffs');
    }
};
