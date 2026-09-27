<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-014): the rule and slab parameters a calculation was priced with — written with
 * every calculation and recalculation, so the calculation view and statement show what was
 * actually applied rather than today's rule. Nullable: calculations priced before 8.6 are not
 * back-filled and are shown as "parameters at the time not recorded".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruiter_incentive_calculations', function (Blueprint $table): void {
            $table->json('pricing_snapshot')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('recruiter_incentive_calculations', function (Blueprint $table): void {
            $table->dropColumn('pricing_snapshot');
        });
    }
};
