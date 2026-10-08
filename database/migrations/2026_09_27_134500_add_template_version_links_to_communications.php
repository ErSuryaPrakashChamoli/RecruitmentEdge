<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-023): a template version also records the WhatsApp approved provider template
 * (a change to it is a new version), and a message links to the exact version row it was rendered
 * from. Additive and nullable; historical messages keep their integer template_version and are
 * not back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_template_versions', function (Blueprint $table): void {
            $table->string('provider_template')->nullable()->after('body');
        });

        Schema::table('candidate_communications', function (Blueprint $table): void {
            $table->foreignId('communication_template_version_id')->nullable()->after('template_version');
            $table->foreign('communication_template_version_id', 'cc_template_version_foreign')->references('id')->on('communication_template_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_communications', function (Blueprint $table): void {
            $table->dropForeign('cc_template_version_foreign');
            $table->dropColumn('communication_template_version_id');
        });

        Schema::table('communication_template_versions', function (Blueprint $table): void {
            $table->dropColumn('provider_template');
        });
    }
};
