<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hiring Memory: immutable, versioned facts captured from real hiring events. Corrections create a
     * new version that supersedes the old one (kept). Phase 8 outcomes will attach in their own table.
     */
    public function up(): void
    {
        Schema::create('hiring_memory_records', function (Blueprint $table) {
            $table->id();
            $table->string('memory_type', 30);
            $table->nullableMorphs('subject');
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained()->nullOnDelete();
            $table->json('facts');
            $table->text('summary');
            $table->timestamp('captured_at');
            $table->string('source_event', 60);
            $table->string('capture_key', 191)->unique();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('hiring_memory_records')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->text('correction_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ai_summary')->nullable();
            $table->string('ai_status', 20)->default('not_requested');
            $table->string('ai_model', 120)->nullable();
            $table->timestamp('ai_generated_at')->nullable();
            $table->timestamps();

            $table->index(['memory_type', 'designation_id', 'is_current'], 'hiring_memory_type_designation');
            $table->index(['requisition_id', 'is_current'], 'hiring_memory_requisition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_memory_records');
    }
};
