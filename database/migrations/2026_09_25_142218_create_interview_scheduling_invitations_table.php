<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recruiter's invitation for one application's candidate to pick an interview slot. The
     * candidate reaches it through a temporary signed URL (keyed by public_id) or the portal.
     */
    public function up(): void
    {
        Schema::create('interview_scheduling_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('candidate_application_id');
            $table->foreign('candidate_application_id', 'isi_application_foreign')->references('id')->on('candidate_applications')->cascadeOnDelete();
            $table->foreignId('interviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('round_name')->nullable();
            $table->dateTime('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['candidate_application_id', 'expires_at'], 'isi_application_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_scheduling_invitations');
    }
};
