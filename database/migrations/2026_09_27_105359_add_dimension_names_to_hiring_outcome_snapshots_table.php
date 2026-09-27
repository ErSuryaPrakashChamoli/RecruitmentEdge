<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-008, hiring-snapshot/3): a snapshot freezes the department, designation,
 * location and source names in force when the hire completed, so a later rename — or an archive —
 * never changes how an outcome is reported. Additive and nullable: snapshots captured under /1 and
 * /2 keep null here and are never back-filled (their labels fall back to the current record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hiring_outcome_snapshots', function (Blueprint $table): void {
            $table->string('department_name')->nullable()->after('source_id');
            $table->string('designation_name')->nullable()->after('department_name');
            $table->string('location_name')->nullable()->after('designation_name');
            $table->string('source_name')->nullable()->after('location_name');
        });
    }

    public function down(): void
    {
        Schema::table('hiring_outcome_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['department_name', 'designation_name', 'location_name', 'source_name']);
        });
    }
};
