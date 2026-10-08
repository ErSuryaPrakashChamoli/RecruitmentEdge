<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-003/024): the reason a person gave for a governed change (deactivating,
 * archiving or restoring master data, editing a setting, re-applying a pipeline, changing an
 * automation rule). Additive and nullable — rows written before 8.6, and changes that need no
 * reason, stay null. Phase 8.7's actor columns are added separately next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->text('reason')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }
};
