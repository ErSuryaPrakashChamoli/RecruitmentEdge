<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distribution attribution (Phase 5): which channel an application arrived through and, for
     * online applications, which job posting. Nullable — every existing application is unaffected.
     */
    public function up(): void
    {
        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->string('origin_channel', 40)->nullable()->after('priority');
            $table->foreignId('job_posting_id')->nullable()->after('origin_channel')->constrained('job_postings')->nullOnDelete();
            $table->index('origin_channel');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->dropIndex(['origin_channel']);
            $table->dropConstrainedForeignId('job_posting_id');
            $table->dropColumn('origin_channel');
        });
    }
};
