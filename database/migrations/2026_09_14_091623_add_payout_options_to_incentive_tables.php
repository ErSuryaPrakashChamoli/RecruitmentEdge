<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recruitment_incentive_rules', function (Blueprint $table) {
            $table->string('payout_type')->default('slab_by_achievement')->after('achievement_metric');
            $table->decimal('fixed_amount', 12, 2)->nullable()->after('payout_type');
            $table->string('slab_upgrade_mode')->default('incremental')->after('fixed_amount');
        });

        Schema::table('recruiter_incentive_calculations', function (Blueprint $table) {
            $table->unsignedInteger('occurrence_count')->nullable()->after('achievement');
        });

        // Before payout types existed, a flat amount per occurrence had to be configured as a rule
        // with no achievement metric and a single 0%-to-uncapped slab. Convert those to Fixed rules.
        DB::table('recruitment_incentive_rules')
            ->whereNull('achievement_metric')
            ->orderBy('id')
            ->each(function (object $rule): void {
                $slabs = DB::table('recruitment_incentive_slabs')->where('incentive_rule_id', $rule->id)->get();
                $onlySlab = $slabs->first();

                if ($slabs->count() === 1 && (float) $onlySlab->achievement_min === 0.0 && $onlySlab->achievement_max === null) {
                    DB::table('recruitment_incentive_rules')
                        ->where('id', $rule->id)
                        ->update(['payout_type' => 'fixed', 'fixed_amount' => $onlySlab->amount]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recruiter_incentive_calculations', function (Blueprint $table) {
            $table->dropColumn('occurrence_count');
        });

        Schema::table('recruitment_incentive_rules', function (Blueprint $table) {
            $table->dropColumn(['payout_type', 'fixed_amount', 'slab_upgrade_mode']);
        });
    }
};
