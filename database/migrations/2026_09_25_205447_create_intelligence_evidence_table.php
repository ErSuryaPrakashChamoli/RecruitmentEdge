<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared provenance for every Phase 7 intelligence value (EDGE INTELLIGENCE). Append-only:
     * rows are never edited except their human verification fields; corrections are new rows or
     * new versions of the owning record.
     */
    public function up(): void
    {
        Schema::create('intelligence_evidence', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('subject_key', 120)->nullable();
            $table->string('evidence_type', 30);
            $table->string('label');
            $table->string('value', 500)->nullable();
            $table->decimal('numeric_value', 14, 4)->nullable();
            $table->nullableMorphs('source');
            $table->timestamp('observed_at')->nullable();
            $table->string('generator', 80);
            $table->string('generator_version', 40);
            $table->string('ai_model', 120)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('explanation')->nullable();
            $table->string('verification_status', 20)->default('not_required');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'subject_key'], 'intel_evidence_owner_subject');
            $table->index(['evidence_type', 'verification_status'], 'intel_evidence_type_verify');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_evidence');
    }
};
