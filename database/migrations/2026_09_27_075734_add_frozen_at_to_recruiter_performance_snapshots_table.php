<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.5 (D48): a month's performance snapshots are frozen once the month is finalised (the day
 * after it closes), so a later change to targets, ownership or activity can never silently rewrite a
 * closed month. Existing snapshots stay unfrozen until the next finalisation run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruiter_performance_snapshots', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('computed_at');
        });
    }

    public function down(): void
    {
        Schema::table('recruiter_performance_snapshots', function (Blueprint $table) {
            $table->dropColumn('frozen_at');
        });
    }
};
