<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-018): the stage list of every pipeline template version, so the
 * `pipeline_template_version` a requisition (and a hiring snapshot) records can be resolved to what
 * that version actually contained. Immutable. Versions before 8.6 are not reconstructed: a
 * template's current definition is captured the first time it is changed or applied after 8.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_pipeline_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_template_id');
            $table->foreign('pipeline_template_id', 'rptv_template_fk')->references('id')->on('recruitment_pipeline_templates')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('stages');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['pipeline_template_id', 'version'], 'rptv_template_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_pipeline_template_versions');
    }
};
