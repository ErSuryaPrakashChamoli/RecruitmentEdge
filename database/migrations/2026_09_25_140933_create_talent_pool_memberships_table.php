<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidate ↔ talent pool membership (many-to-many with metadata). One row per pair, ever:
     * removal sets removed_* and re-adding reactivates the row, so duplicate membership is
     * impossible at the database level. The full add/remove history lives in audit_logs.
     */
    public function up(): void
    {
        Schema::create('talent_pool_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_pool_id')->constrained('talent_pools')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->string('source', 30)->default('manual');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('removed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->unique(['talent_pool_id', 'candidate_id'], 'tpm_pool_candidate_unique');
            $table->index(['candidate_id', 'removed_at'], 'tpm_candidate_removed_index');
            $table->index(['talent_pool_id', 'removed_at'], 'tpm_pool_removed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_pool_memberships');
    }
};
