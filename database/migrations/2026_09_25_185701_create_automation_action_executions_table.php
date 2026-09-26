<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each configured action gets its own result row, so an execution can be Partially Completed
     * and a manual retry re-runs only the failed actions. `target` links what the action produced
     * (recruiter action, communication, follow-up...).
     */
    public function up(): void
    {
        Schema::create('automation_action_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_execution_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('action_type', 40)->index();
            $table->string('status', 20)->default('pending');
            $table->string('summary')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->nullableMorphs('target');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_execution_id', 'position'], 'automation_action_exec_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_action_executions');
    }
};
