<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An immutable snapshot of a pipeline template's stages taken when the template is applied to a
     * requisition, so later template edits never alter an existing requisition's pipeline. Rows are
     * never deleted: re-applying a template marks the previous snapshot `superseded_at`, which keeps
     * every stage history row that references it resolvable.
     */
    public function up(): void
    {
        Schema::create('requisition_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('recruitment_requisitions')->cascadeOnDelete();
            $table->foreignId('recruitment_stage_id')->nullable()->constrained('recruitment_stages')->nullOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->string('stage_type', 30);
            $table->string('milestone', 40);
            $table->string('icon')->nullable();
            $table->string('color', 20)->default('gray');
            $table->unsignedInteger('sort_order')->default(0);
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
            $table->json('transitions')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['requisition_id', 'superseded_at', 'sort_order'], 'rps_req_current_sort_index');
            $table->index('milestone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_pipeline_stages');
    }
};
