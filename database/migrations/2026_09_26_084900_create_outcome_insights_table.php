<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.2: learning insights derived from aggregated outcomes, each with its evidence, sample
 * size, observation period, confidence band and limitations. They change nothing until a person
 * accepts them (Role DNA suggestion → new Role DNA version; source pattern → Hiring Memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outcome_insights', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->string('status', 20);
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->string('subject_key', 120)->nullable();
            $table->text('insight');
            $table->text('suggested_change')->nullable();
            $table->json('evidence');
            $table->unsignedInteger('sample_size');
            $table->string('sample_band', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('confidence', 20);
            $table->text('limitations');
            $table->json('source_refs');
            $table->string('rule_version', 40);
            $table->text('ai_summary')->nullable();
            $table->string('ai_status', 20)->default('not_requested');
            $table->string('ai_model', 120)->nullable();
            $table->timestamp('ai_generated_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason')->nullable();
            $table->string('applied_ref', 120)->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->timestamp('last_recalculated_at');
            $table->timestamps();

            $table->index(['status', 'kind'], 'outcome_insights_status_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outcome_insights');
    }
};
