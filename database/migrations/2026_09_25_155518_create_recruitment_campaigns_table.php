<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recruitment campaigns (Phase 5) grouping requisitions and sources under one budget/target.
     * `code` is the public tracking code used in career-site links (?campaign=CODE) for candidate
     * attribution. Metrics are computed by RecruitmentAnalyticsService from existing records —
     * nothing is duplicated here.
     */
    public function up(): void
    {
        Schema::create('recruitment_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->unsignedInteger('target_hires')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('recruitment_campaign_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_campaign_id');
            $table->foreign('recruitment_campaign_id', 'rcr_campaign_foreign')->references('id')->on('recruitment_campaigns')->cascadeOnDelete();
            $table->foreignId('requisition_id');
            $table->foreign('requisition_id', 'rcr_requisition_foreign')->references('id')->on('recruitment_requisitions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['recruitment_campaign_id', 'requisition_id'], 'rcr_campaign_requisition_unique');
        });

        Schema::create('recruitment_campaign_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_campaign_id');
            $table->foreign('recruitment_campaign_id', 'rcs_campaign_foreign')->references('id')->on('recruitment_campaigns')->cascadeOnDelete();
            $table->foreignId('source_id');
            $table->foreign('source_id', 'rcs_source_foreign')->references('id')->on('candidate_sources')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['recruitment_campaign_id', 'source_id'], 'rcs_campaign_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_campaign_sources');
        Schema::dropIfExists('recruitment_campaign_requisitions');
        Schema::dropIfExists('recruitment_campaigns');
    }
};
