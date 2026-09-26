<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only: every new column is nullable (or defaulted) so existing requisitions,
     * applications and stage history rows keep working untouched until
     * `recruitment:assign-default-pipelines` backfills them.
     */
    public function up(): void
    {
        Schema::table('recruitment_requisitions', function (Blueprint $table) {
            $table->foreignId('pipeline_template_id')->nullable()->after('status')->constrained('recruitment_pipeline_templates')->nullOnDelete();
            $table->unsignedInteger('pipeline_template_version')->nullable()->after('pipeline_template_id');
            $table->timestamp('pipeline_applied_at')->nullable()->after('pipeline_template_version');
            $table->foreignId('pipeline_applied_by')->nullable()->after('pipeline_applied_at')->constrained('employees')->nullOnDelete();
        });

        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->foreignId('pipeline_stage_id')->nullable()->after('current_stage')->constrained('requisition_pipeline_stages')->nullOnDelete();
        });

        Schema::table('candidate_stage_histories', function (Blueprint $table) {
            $table->foreignId('previous_pipeline_stage_id')->nullable()->after('new_stage');
            $table->foreign('previous_pipeline_stage_id', 'csh_prev_pipeline_stage_foreign')->references('id')->on('requisition_pipeline_stages')->nullOnDelete();
            $table->foreignId('new_pipeline_stage_id')->nullable()->after('previous_pipeline_stage_id');
            $table->foreign('new_pipeline_stage_id', 'csh_new_pipeline_stage_foreign')->references('id')->on('requisition_pipeline_stages')->nullOnDelete();
            $table->boolean('is_override')->default(false)->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_stage_histories', function (Blueprint $table) {
            $table->dropForeign('csh_prev_pipeline_stage_foreign');
            $table->dropForeign('csh_new_pipeline_stage_foreign');
            $table->dropColumn(['previous_pipeline_stage_id', 'new_pipeline_stage_id', 'is_override']);
        });

        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_stage_id');
        });

        Schema::table('recruitment_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_template_id');
            $table->dropConstrainedForeignId('pipeline_applied_by');
            $table->dropColumn(['pipeline_template_version', 'pipeline_applied_at']);
        });
    }
};
