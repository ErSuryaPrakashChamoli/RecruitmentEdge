<?php

namespace Database\Seeders\Demo;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Interviewer;
use App\Models\Location;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentSetting;
use App\Models\User;

/**
 * Builds the demo company a month before the story starts: offices, departments, designations,
 * people and their logins, the interviewer list, and the configuration the rest of the product runs
 * on (targets, performance weights, incentive rules, the dashboard quote).
 */
final class DemoOrganization
{
    public function __construct(private readonly DemoContext $ctx) {}

    public function build(): void
    {
        DemoContext::freeze($this->ctx->storyStart->subDays(30)->setTime(10, 0));

        try {
            $this->createStructure();
            $this->createPeople();
            $this->loadReferenceData();
            $this->ctx->actAs($this->ctx->person('vp_hr'));
            $this->configureTargetsAndPerformance();
            $this->configureIncentives();

            RecruitmentSetting::put('dashboard.quote_text', 'Great teams are built one great hire at a time.', group: 'dashboard', description: 'Dashboard greeting quote of the day');
            RecruitmentSetting::put('dashboard.quote_icon', 'heroicon-o-sparkles', group: 'dashboard', description: 'Icon shown next to the dashboard quote of the day');
        } finally {
            $this->ctx->actAs(null);
            DemoContext::freeze(null);
        }
    }

    private function createStructure(): void
    {
        foreach (DemoCatalog::LOCATIONS as $code => $location) {
            $this->ctx->locations[$code] = Location::query()->updateOrCreate(
                ['code' => $code],
                [...$location, 'country' => 'India', 'is_active' => true],
            );
        }

        foreach (DemoCatalog::DEPARTMENTS as $code => $name) {
            $this->ctx->departments[$code] = Department::query()->updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        foreach (DemoCatalog::DESIGNATIONS as $code => [$name, $department]) {
            Designation::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'department_id' => $this->ctx->departments[$department]->id, 'is_active' => true],
            );
        }

        $this->ctx->designations = Designation::query()->get()->keyBy('code')->all();
    }

    private function createPeople(): void
    {
        foreach (DemoCatalog::PEOPLE as $key => $person) {
            $employee = Employee::query()->create([
                'employee_code' => $this->ctx->code('EMP'),
                'first_name' => $person['first'],
                'last_name' => $person['last'],
                'email' => DemoCatalog::personEmail($key),
                'mobile' => $this->ctx->mobile(),
                'department_id' => $this->ctx->departments[$person['department']]->id,
                'designation_id' => $this->ctx->designations[$person['designation']]->id,
                'location_id' => $this->ctx->locations[$person['location']]->id,
                'reports_to_id' => $person['reports_to'] !== null ? $this->ctx->person($person['reports_to'])->id : null,
                'date_of_joining' => $this->ctx->storyStart->subDays($person['years'] * 365 + $this->ctx->number(10, 300))->toDateString(),
                'status' => EmployeeStatus::Active,
                'category' => $person['category'],
                'level' => $person['level'],
            ]);

            $user = null;

            if (isset($person['role'])) {
                $user = User::query()->create([
                    'name' => $employee->fullName(),
                    'email' => DemoCatalog::personEmail($key),
                    'password' => (string) config('demo.password'),
                    'employee_id' => $employee->id,
                    'email_verified_at' => now(),
                ]);

                $user->assignRole($person['role']);
            }

            $employee->setRelation('user', $user);
            $this->ctx->people[$key] = $employee;
        }

        foreach (DemoCatalog::INTERVIEWERS as $key) {
            Interviewer::query()->create(['employee_id' => $this->ctx->person($key)->id, 'is_active' => true]);
        }
    }

    private function loadReferenceData(): void
    {
        $this->ctx->sources = CandidateSource::query()->get()->keyBy('name')->all();
        $this->ctx->reasons = RecruitmentRejectionReason::query()->get()->keyBy('name')->all();
    }

    /**
     * Recruiter targets (set on the Recruiter designation, with one personal override) and the
     * weights of the composite performance score.
     */
    private function configureTargetsAndPerformance(): void
    {
        $effectiveFrom = $this->ctx->storyStart->subDays(30)->toDateString();
        $createdBy = $this->ctx->person('vp_hr')->id;

        $targets = [
            [TargetMetric::Calls, TargetPeriodType::Daily, 35],
            [TargetMetric::ConnectedCalls, TargetPeriodType::Daily, 14],
            [TargetMetric::ProfilesSourced, TargetPeriodType::Weekly, 9],
            [TargetMetric::InterestedCandidates, TargetPeriodType::Weekly, 7],
            [TargetMetric::Screening, TargetPeriodType::Weekly, 5],
            [TargetMetric::Shortlisted, TargetPeriodType::Weekly, 3],
            [TargetMetric::Interviews, TargetPeriodType::Monthly, 16],
            [TargetMetric::Selections, TargetPeriodType::Monthly, 5],
            [TargetMetric::Offers, TargetPeriodType::Monthly, 5],
            [TargetMetric::Joining, TargetPeriodType::Monthly, 3],
        ];

        foreach ($targets as [$metric, $periodType, $value]) {
            RecruitmentDailyTarget::query()->create([
                'designation_id' => $this->ctx->designations['DSG-RCT']->id,
                'metric' => $metric,
                'period_type' => $periodType,
                'target_value' => $value,
                'effective_from' => $effectiveFrom,
                'created_by' => $createdBy,
            ]);
        }

        RecruitmentDailyTarget::query()->create([
            'employee_id' => $this->ctx->person('r_priya')->id,
            'metric' => TargetMetric::Calls,
            'period_type' => TargetPeriodType::Daily,
            'target_value' => 40,
            'effective_from' => $effectiveFrom,
            'created_by' => $createdBy,
        ]);

        $weights = [
            TargetMetric::Calls->value => 10, TargetMetric::ConnectedCalls->value => 10, TargetMetric::ProfilesSourced->value => 10,
            TargetMetric::InterestedCandidates->value => 10, TargetMetric::Screening->value => 10, TargetMetric::Shortlisted->value => 10,
            TargetMetric::Interviews->value => 15, TargetMetric::Selections->value => 10, TargetMetric::Offers->value => 5,
            TargetMetric::Joining->value => 10,
        ];

        foreach ($weights as $metric => $weight) {
            RecruiterPerformanceRule::query()->create([
                'metric' => $metric,
                'weightage' => $weight,
                'effective_from' => $effectiveFrom,
                'created_by' => $createdBy,
            ]);
        }
    }

    private function configureIncentives(): void
    {
        $effectiveFrom = $this->ctx->storyStart->subDays(30)->toDateString();
        $createdBy = $this->ctx->person('vp_hr')->id;
        $recruiterDesignation = $this->ctx->designations['DSG-RCT']->id;

        RecruitmentIncentiveRule::query()->create([
            'name' => 'Joining Incentive — Recruiters',
            'trigger_event' => IncentiveTriggerEvent::Joining,
            'payout_type' => IncentivePayoutType::Fixed,
            'fixed_amount' => 2500,
            'slab_upgrade_mode' => IncentiveSlabUpgradeMode::Incremental,
            'designation_id' => $recruiterDesignation,
            'retention_days' => 90,
            'effective_from' => $effectiveFrom,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);

        $monthlySlab = RecruitmentIncentiveRule::query()->create([
            'name' => 'Monthly Joining Slab Bonus',
            'trigger_event' => IncentiveTriggerEvent::Joining,
            'payout_type' => IncentivePayoutType::SlabByCount,
            'slab_upgrade_mode' => IncentiveSlabUpgradeMode::Retroactive,
            'department_id' => $this->ctx->departments['TA']->id,
            'effective_from' => $effectiveFrom,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);

        foreach ([[1, 2, 1000], [3, 4, 1500], [5, null, 2500]] as [$min, $max, $amount]) {
            $monthlySlab->slabs()->create(['achievement_min' => $min, 'achievement_max' => $max, 'amount' => $amount]);
        }

        $selection = RecruitmentIncentiveRule::query()->create([
            'name' => 'Selection Achievement Bonus',
            'trigger_event' => IncentiveTriggerEvent::Selection,
            'achievement_metric' => TargetMetric::Selections,
            'payout_type' => IncentivePayoutType::SlabByAchievement,
            'slab_upgrade_mode' => IncentiveSlabUpgradeMode::Incremental,
            'designation_id' => $recruiterDesignation,
            'effective_from' => $effectiveFrom,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);

        foreach ([[80, 99.99, 400], [100, null, 750]] as [$min, $max, $amount]) {
            $selection->slabs()->create(['achievement_min' => $min, 'achievement_max' => $max, 'amount' => $amount]);
        }

        RecruitmentIncentiveRule::query()->create([
            'name' => 'Tech Hiring Premium — Divya Joshi',
            'trigger_event' => IncentiveTriggerEvent::OfferAccepted,
            'payout_type' => IncentivePayoutType::Fixed,
            'fixed_amount' => 3000,
            'slab_upgrade_mode' => IncentiveSlabUpgradeMode::Incremental,
            'employee_id' => $this->ctx->person('r_divya')->id,
            'employment_type' => EmploymentType::Permanent,
            'effective_from' => $effectiveFrom,
            'is_active' => true,
            'created_by' => $createdBy,
        ]);

        RecruitmentIncentiveRule::query()->create([
            'name' => 'Walk-in Drive Bonus (last quarter)',
            'trigger_event' => IncentiveTriggerEvent::Joining,
            'payout_type' => IncentivePayoutType::Fixed,
            'fixed_amount' => 1000,
            'slab_upgrade_mode' => IncentiveSlabUpgradeMode::Incremental,
            'designation_id' => $recruiterDesignation,
            'effective_from' => $this->ctx->storyStart->subDays(120)->toDateString(),
            'effective_to' => $this->ctx->storyStart->subDays(31)->toDateString(),
            'is_active' => false,
            'created_by' => $createdBy,
        ]);
    }
}
