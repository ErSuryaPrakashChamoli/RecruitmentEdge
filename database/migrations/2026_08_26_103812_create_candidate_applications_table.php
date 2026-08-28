<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('candidate_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_code')->unique();
            $table->foreignId('candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->foreignId('requisition_id')->constrained('recruitment_requisitions')->restrictOnDelete();
            $table->foreignId('recruiter_id')->constrained('employees')->restrictOnDelete();
            $table->string('current_stage')->default('sourced');
            $table->date('application_date');
            $table->string('priority')->default('medium');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('rejection_reason_id')->nullable()->constrained('recruitment_rejection_reasons')->nullOnDelete();
            $table->foreignId('dropout_reason_id')->nullable()->constrained('recruitment_rejection_reasons')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['candidate_id', 'requisition_id']);
            $table->index('recruiter_id');
            $table->index('current_stage');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_applications');
    }
};
