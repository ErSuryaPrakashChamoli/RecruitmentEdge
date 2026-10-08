<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.2 Outcome Loop: deterministic outcomes and status observations. One current version per
 * dedupe_key; a correction adds a new version and keeps the old one (is_current = false), so no
 * calculation is ever silently overwritten. Provenance: source morph + rule_version + observed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hiring_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->string('outcome_type', 40);
            $table->string('category', 20);
            $table->string('state', 20);
            $table->string('result', 40);
            $table->decimal('value', 10, 2)->nullable();
            $table->string('unit', 10)->nullable();
            $table->string('confidence', 10);
            $table->string('capture_mode', 30);
            $table->foreignId('hiring_outcome_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_joining_id')->nullable()->constrained()->nullOnDelete();
            $table->date('observation_start')->nullable();
            $table->date('observation_end')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->nullableMorphs('source');
            $table->string('rule_version', 40);
            $table->json('details')->nullable();
            $table->string('dedupe_key', 191);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('hiring_outcomes')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correction_reason')->nullable();
            $table->timestamps();

            $table->unique(['dedupe_key', 'version'], 'hiring_outcomes_dedupe_version');
            $table->index(['dedupe_key', 'is_current'], 'hiring_outcomes_dedupe_current');
            $table->index(['outcome_type', 'is_current', 'observation_end'], 'hiring_outcomes_type_current');
            $table->index(['requisition_id', 'is_current'], 'hiring_outcomes_requisition_current');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_outcomes');
    }
};
