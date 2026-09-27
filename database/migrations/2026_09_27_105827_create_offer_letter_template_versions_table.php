<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-011): every change to an offer letter template's wording or Word file is kept as
 * an immutable version; a superseded Word file is no longer deleted. Versions are created from the
 * first change (or first issued letter) after 8.6 — nothing is back-filled. `version` on the
 * template is its current version number (0 until the first version is recorded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letter_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_letter_template_id');
            $table->foreign('offer_letter_template_id', 'oltv_template_fk')->references('id')->on('offer_letter_templates')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('format', 20);
            $table->longText('body')->nullable();
            $table->string('file_path')->nullable();
            $table->string('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['offer_letter_template_id', 'version'], 'oltv_template_version_unique');
        });

        Schema::table('offer_letter_templates', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('offer_letter_templates', function (Blueprint $table): void {
            $table->dropColumn('version');
        });

        Schema::dropIfExists('offer_letter_template_versions');
    }
};
