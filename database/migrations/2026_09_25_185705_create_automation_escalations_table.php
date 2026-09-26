<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled escalation steps of a completed execution. The recipient is resolved through
     * HierarchyService only when the step becomes due, and the step is Stopped instead of sent
     * when the issue was resolved in the meantime.
     */
    public function up(): void
    {
        Schema::create('automation_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('target', 40);
            $table->string('status', 20)->default('pending');
            $table->timestamp('due_at');
            $table->foreignId('recipient_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recruiter_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at'], 'automation_escalations_due');
            $table->unique(['automation_execution_id', 'step'], 'automation_escalations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_escalations');
    }
};
