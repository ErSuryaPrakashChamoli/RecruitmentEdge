<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.2 Outcome Loop: one immutable snapshot of the hiring journey per completed join,
 * anchored to the joining record (never to the Joined pipeline stage). References and safe
 * categories only — no names, contact details or pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hiring_outcome_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_joining_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('candidate_sources')->nullOnDelete();
            $table->foreignId('role_dna_version_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('pipeline_template_version')->nullable();
            $table->date('joined_on');
            $table->unsignedInteger('time_to_hire_days')->nullable();
            $table->json('facts');
            $table->string('capture_mode', 30);
            $table->string('rules_version', 40);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['designation_id', 'joined_on'], 'outcome_snapshots_designation_joined');
            $table->index(['joined_on'], 'outcome_snapshots_joined');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_outcome_snapshots');
    }
};
