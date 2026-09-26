<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hiring Risk Radar register. `open_key` is set only while a risk is open, so each type + subject
     * has at most one open risk while its history is kept.
     */
    public function up(): void
    {
        Schema::create('hiring_risks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->cascadeOnDelete();
            $table->nullableMorphs('subject');
            $table->foreignId('candidate_application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('recommended_action')->nullable();
            $table->string('detector_version', 40);
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('open_key', 191)->nullable()->unique();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_seen_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution')->nullable();
            $table->foreignId('recruiter_action_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'severity'], 'hiring_risks_status_severity');
            $table->index(['requisition_id', 'status'], 'hiring_risks_requisition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_risks');
    }
};
