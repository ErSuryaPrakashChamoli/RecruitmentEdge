<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.9 (P89-PERF-024): a Word offer letter's PDF conversion (LibreOffice, up to 120 s) left the
 * release request. The release records here — inside its transaction — the letter as it must read
 * (the filled .docx, stored privately) and its template version; a queued job converts it and only
 * then issues the immutable offer_letters row (D8.6-010), with the release time as its issue time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letter_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_revision_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('offer_letter_template_id')->nullable();
            $table->foreign('offer_letter_template_id', 'olc_template_fk')->references('id')->on('offer_letter_templates')->restrictOnDelete();
            $table->foreignId('offer_letter_template_version_id')->nullable();
            $table->foreign('offer_letter_template_version_id', 'olc_template_version_fk')->references('id')->on('offer_letter_template_versions')->restrictOnDelete();
            $table->string('document_path');
            $table->string('status', 20)->default('pending');
            $table->foreignId('offer_letter_id')->nullable();
            $table->foreign('offer_letter_id', 'olc_offer_letter_fk')->references('id')->on('offer_letters')->restrictOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'status'], 'olc_offer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letter_conversions');
    }
};
