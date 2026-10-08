<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One rule run against one entity. `idempotency_key` (rule + version + trigger + entity +
     * time anchor) makes duplicate events harmless; `depth`/`parent_execution_id` record the
     * automation chain for loop prevention; `scheduled_for` holds time-based runs until due.
     */
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 60);
            $table->nullableMorphs('subject');
            $table->foreignId('candidate_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recruiter_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 30)->default('pending');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('conditions_passed')->nullable();
            $table->json('condition_results')->nullable();
            $table->json('context')->nullable();
            $table->string('skip_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedTinyInteger('depth')->default(0);
            $table->foreignId('parent_execution_id')->nullable()->constrained('automation_executions')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_for'], 'automation_exec_due');
            $table->index(['automation_rule_id', 'created_at'], 'automation_exec_rule_created');
            $table->index(['automation_rule_id', 'subject_type', 'subject_id', 'completed_at'], 'automation_exec_cooldown');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};
