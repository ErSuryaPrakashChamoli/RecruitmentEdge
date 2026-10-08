<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4.1: an explicit incentive subject on the existing calculations table, so referral
     * bonuses (paid to the referring employee) never appear in recruiter scorecards, team totals
     * or statements. Additive: every existing row defaults to `recruiter`; rows already priced by a
     * referral_joining rule are corrected to `employee_referrer` and linked to their referral.
     */
    public function up(): void
    {
        Schema::table('recruiter_incentive_calculations', function (Blueprint $table) {
            $table->string('beneficiary_type', 30)->default('recruiter')->after('employee_id');
            $table->foreignId('employee_referral_id')->nullable()->after('candidate_application_id');
            $table->foreign('employee_referral_id', 'incentive_calc_referral_foreign')->references('id')->on('employee_referrals')->nullOnDelete();
            $table->index(['beneficiary_type', 'employee_id'], 'incentive_calc_beneficiary_index');
        });

        $referralRuleIds = DB::table('recruitment_incentive_rules')->where('trigger_event', 'referral_joining')->pluck('id');

        if ($referralRuleIds->isNotEmpty()) {
            DB::table('recruiter_incentive_calculations')
                ->whereIn('incentive_rule_id', $referralRuleIds)
                ->update(['beneficiary_type' => 'employee_referrer']);

            DB::table('employee_referrals')
                ->whereNotNull('incentive_calculation_id')
                ->get(['id', 'incentive_calculation_id'])
                ->each(fn ($referral) => DB::table('recruiter_incentive_calculations')
                    ->where('id', $referral->incentive_calculation_id)
                    ->update(['employee_referral_id' => $referral->id]));
        }
    }

    public function down(): void
    {
        Schema::table('recruiter_incentive_calculations', function (Blueprint $table) {
            $table->dropIndex('incentive_calc_beneficiary_index');
            $table->dropForeign('incentive_calc_referral_foreign');
            $table->dropColumn(['beneficiary_type', 'employee_referral_id']);
        });
    }
};
