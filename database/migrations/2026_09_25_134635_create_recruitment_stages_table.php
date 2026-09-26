<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The library of configurable hiring stages (Phase 4). Every stage maps to a canonical
     * CandidateStage `milestone` so funnel/SLA/incentive analytics keep one well-defined order.
     */
    public function up(): void
    {
        Schema::create('recruitment_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 60)->unique();
            $table->text('description')->nullable();
            $table->string('stage_type', 30);
            $table->string('milestone', 40);
            $table->string('icon')->nullable();
            $table->string('color', 20)->default('gray');
            $table->unsignedInteger('sla_hours')->nullable();
            $table->boolean('requires_candidate_action')->default(false);
            $table->boolean('requires_recruiter_action')->default(true);
            $table->boolean('is_interview_stage')->default(false);
            $table->boolean('is_offer_stage')->default(false);
            $table->boolean('is_joining_stage')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_skippable')->default(true);
            $table->boolean('allows_rejection')->default(true);
            $table->boolean('allows_dropout')->default(true);
            $table->json('requirements')->nullable();
            $table->boolean('candidate_visible')->default(true);
            $table->string('candidate_label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'recruitment_stages_active_sort_index');
            $table->index('milestone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_stages');
    }
};
