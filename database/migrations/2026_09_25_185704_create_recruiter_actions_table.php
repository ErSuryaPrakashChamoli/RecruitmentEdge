<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent, owned Action Center items (Phase 6). Distinct from recruitment_followups, which
     * stays the recruiter's candidate-contact log feeding performance metrics. `dedupe_key`
     * prevents the same automation creating the same action twice.
     */
    public function up(): void
    {
        Schema::create('recruiter_actions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('action_type', 40);
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->text('reason')->nullable();
            $table->string('suggested_action')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['owner_id', 'status', 'due_at'], 'recruiter_actions_owner_queue');
            $table->index(['status', 'due_at'], 'recruiter_actions_status_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruiter_actions');
    }
};
