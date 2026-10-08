<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable snapshot of every published template version, so a sent message can always be
     * traced back to the exact wording it used (candidate_communications.template_version).
     */
    public function up(): void
    {
        Schema::create('communication_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_template_id');
            $table->foreign('communication_template_id', 'ctv_template_foreign')->references('id')->on('communication_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['communication_template_id', 'version'], 'ctv_template_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_template_versions');
    }
};
