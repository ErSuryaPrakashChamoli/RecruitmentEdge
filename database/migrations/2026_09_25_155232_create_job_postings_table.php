<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The public-facing advert for a requisition (Phase 5 Job Distribution). The requisition stays
     * authoritative for position data (department, location, experience, openings); the posting only
     * holds public wording and publishing state. One posting per requisition.
     */
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->unique()->constrained('recruitment_requisitions')->cascadeOnDelete();
            $table->string('public_slug')->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('description');
            $table->boolean('show_salary')->default(false);
            $table->string('status', 20)->default('draft');
            $table->date('closes_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
