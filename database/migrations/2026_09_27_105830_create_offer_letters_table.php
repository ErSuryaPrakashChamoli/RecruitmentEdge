<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-010): the offer letter exactly as issued. Rendered once when the offer is
 * released and again when a revision is released, stored privately with its SHA-256, and served
 * from storage from then on — a later template edit, default change or merge-value change never
 * alters an issued letter. Rows are immutable. Offers released before 8.6 have no row (not
 * back-filled); their download is regenerated and labelled as such.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_revision_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('source', 20);
            $table->foreignId('offer_letter_template_id')->nullable();
            $table->foreign('offer_letter_template_id', 'offer_letters_template_fk')->references('id')->on('offer_letter_templates')->restrictOnDelete();
            $table->foreignId('offer_letter_template_version_id')->nullable();
            $table->foreign('offer_letter_template_version_id', 'offer_letters_template_version_fk')->references('id')->on('offer_letter_template_versions')->restrictOnDelete();
            $table->string('file_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->foreignId('issued_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['offer_id', 'issued_at'], 'offer_letters_offer_issued');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letters');
    }
};
