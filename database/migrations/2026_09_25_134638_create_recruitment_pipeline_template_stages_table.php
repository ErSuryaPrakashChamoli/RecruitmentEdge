<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ordered stages of a pipeline template, with optional per-template overrides of the
     * library stage's SLA and skippability.
     */
    public function up(): void
    {
        Schema::create('recruitment_pipeline_template_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_template_id');
            $table->foreign('pipeline_template_id', 'rpts_template_foreign')->references('id')->on('recruitment_pipeline_templates')->cascadeOnDelete();
            $table->foreignId('recruitment_stage_id');
            $table->foreign('recruitment_stage_id', 'rpts_stage_foreign')->references('id')->on('recruitment_stages')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('sla_hours')->nullable();
            $table->boolean('is_skippable')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_template_id', 'recruitment_stage_id'], 'rpts_template_stage_unique');
            $table->index('recruitment_stage_id', 'rpts_stage_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_pipeline_template_stages');
    }
};
