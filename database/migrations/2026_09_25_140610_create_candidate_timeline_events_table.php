<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only store for candidate timeline events that have no other authoritative table.
     * Existing sources (stage history, interviews, offers, activities, follow-ups) are merged in
     * at read time by CandidateTimelineService and are never copied here.
     */
    public function up(): void
    {
        Schema::create('candidate_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained('candidate_applications')->nullOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->foreignId('interview_id')->nullable()->constrained('interviews')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('candidate_joining_id')->nullable()->constrained('candidate_joinings')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('source', 30);
            $table->string('visibility', 20)->default('internal');
            $table->nullableMorphs('actor');
            $table->nullableMorphs('subject');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['candidate_id', 'occurred_at'], 'cte_candidate_occurred_index');
            $table->index(['candidate_application_id', 'occurred_at'], 'cte_application_occurred_index');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_timeline_events');
    }
};
