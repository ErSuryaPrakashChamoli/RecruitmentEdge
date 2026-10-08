<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campaign attribution on the existing records (Phase 5): which campaign an application came
     * through, and which campaign a recruitment cost belongs to. The free-text
     * recruitment_costs.campaign column is kept for existing data.
     */
    public function up(): void
    {
        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('job_posting_id')->constrained('recruitment_campaigns')->nullOnDelete();
        });

        Schema::table('recruitment_costs', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('campaign')->constrained('recruitment_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
