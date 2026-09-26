<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Talent Signal: a candidate's deterministic, decomposed alignment with one Role DNA version.
     * Reportable summary columns are real columns; the component breakdown is an immutable JSON
     * snapshot with its evidence in intelligence_evidence.
     */
    public function up(): void
    {
        Schema::create('talent_signal_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requisition_id')->constrained('recruitment_requisitions')->cascadeOnDelete();
            $table->foreignId('role_dna_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rules_version', 40);
            $table->string('band', 30);
            $table->unsignedSmallInteger('required_skills')->default(0);
            $table->unsignedSmallInteger('required_skills_matched')->default(0);
            $table->decimal('required_coverage_pct', 5, 1)->nullable();
            $table->string('experience_fit', 20)->default('unknown');
            $table->decimal('completeness_pct', 5, 1)->default(0);
            $table->unsignedSmallInteger('evidence_count')->default(0);
            $table->json('components');
            $table->boolean('is_current')->default(true);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['requisition_id', 'is_current', 'band'], 'talent_signal_req_current');
            $table->index(['candidate_id', 'is_current'], 'talent_signal_candidate_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_signal_snapshots');
    }
};
