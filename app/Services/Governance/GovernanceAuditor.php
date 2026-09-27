<?php

namespace App\Services\Governance;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationScope;
use App\Enums\IncentivePayoutType;
use App\Enums\OfferStatus;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\CandidateSource;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GovernedMasterData;
use App\Models\Department;
use App\Models\Designation;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Location;
use App\Models\Offer;
use App\Models\OfferLetter;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\RecruitmentSettingChange;
use App\Services\MasterDataLifecycleService;
use App\Services\Metrics\MetricPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.6 (D8.6-029): the READ-ONLY governance audit. It reports configuration and master-data
 * states that Phase 8.6 now prevents but that existing data may contain, and the historical
 * records that cannot be fully reproduced. It only reads: nothing is repaired, changed or audited
 * — safe to run in production. Any repair needs its own approved decision.
 *
 * - ERROR: a state that is wrong under any version (e.g. an automation rule scoped to a department
 *   that no longer exists, an overlapping target).
 * - WARNING: a gap earlier versions allowed that 8.6 now closes (e.g. an outcome snapshot whose
 *   department was blanked by a permanent delete).
 * - INFO: counts of records made before a safeguard existed — the documented reproducibility
 *   limits (e.g. offers released before issued letters were stored).
 */
class GovernanceAuditor
{
    public const string ERROR = 'ERROR';

    public const string WARNING = 'WARNING';

    public const string INFO = 'INFO';

    public function __construct(private readonly ConfigurationFingerprint $fingerprint) {}

    /**
     * @return array<int, array{level: string, area: string, check: string, count: int, detail: string}>
     */
    public function run(): array
    {
        $findings = [];
        $add = function (string $level, string $area, string $check, int $count, string $detail) use (&$findings): void {
            if ($count > 0 || $level === self::INFO) {
                $findings[] = ['level' => $level, 'area' => $area, 'check' => $check, 'count' => $count, 'detail' => $detail];
            }
        };

        $this->masterData($add);
        $this->configuration($add);
        $this->dependencies($add);
        $this->effectiveRanges($add);
        $this->reproducibility($add);

        return $findings;
    }

    private function masterData(callable $add): void
    {
        foreach (MasterDataLifecycleService::GOVERNED as $class) {
            $guarded = in_array(GovernedMasterData::class, class_uses_recursive($class), true) && in_array(Auditable::class, class_uses_recursive($class), true);
            $add($guarded ? self::INFO : self::ERROR, 'master data', 'force_delete_guard', $guarded ? 0 : 1, class_basename($class).($guarded ? ': permanent delete refused, changes audited.' : ': NOT protected against permanent delete or not audited.'));
        }

        $openStatuses = array_map(fn ($status) => $status->value, MasterDataLifecycleService::OPEN_REQUISITION_STATUSES);

        foreach (['department_id' => Department::class, 'designation_id' => Designation::class, 'location_id' => Location::class] as $column => $class) {
            $table = (new $class)->getTable();
            $archived = RecruitmentRequisition::query()->whereIn('status', $openStatuses)
                ->whereIn($column, fn ($q) => $q->select('id')->from($table)->whereNotNull('deleted_at'))->count();
            $inactive = RecruitmentRequisition::query()->whereIn('status', $openStatuses)
                ->whereIn($column, fn ($q) => $q->select('id')->from($table)->whereNull('deleted_at')->where('is_active', false))->count();

            $add(self::WARNING, 'master data', 'open_work_on_archived_'.class_basename($class), $archived, "Open requisitions use an archived {$table} row (archived before 8.6's in-use check).");
            $add(self::INFO, 'master data', 'open_work_on_inactive_'.class_basename($class), $inactive, "Open requisitions still use an inactive {$table} row (allowed: inactive only blocks new use).");
        }

        $missingSystem = collect(CandidateSource::SYSTEM_CODES)->reject(fn (string $code) => CandidateSource::query()->where('code', $code)->where('is_active', true)->exists())->count();
        $add(self::ERROR, 'master data', 'system_sources_missing', $missingSystem, 'A candidate source the application looks up by code (Website, Employee Referral) is missing or inactive.');

        $unaudited = AuditLog::query()
            ->whereIn('auditable_type', MasterDataLifecycleService::GOVERNED)
            ->whereIn('action', ['archived', 'restored'])
            ->whereNull('reason')
            ->count();
        $add(self::WARNING, 'audit', 'master_data_change_without_reason', $unaudited, 'Archive/restore audit rows without a reason (made outside MasterDataLifecycleService).');
    }

    private function configuration(callable $add): void
    {
        foreach ($this->fingerprint->drift() as $group => $problem) {
            $add(self::ERROR, 'configuration', "config_drift_{$group}", 1, "The running {$group} configuration is not the reviewed one: {$problem}.");
        }

        $firstRecorded = RecruitmentSettingChange::query()->min('created_at');
        $untracked = AuditLog::query()->where('auditable_type', RecruitmentSetting::class)->where('action', 'updated')
            ->when($firstRecorded !== null, fn ($q) => $q->where('created_at', '<', $firstRecorded))
            ->count();
        $add(self::INFO, 'configuration', 'setting_changes_before_history', $untracked, 'Setting changes made before the setting history existed: only the audit log holds them, so as-of values before the first recorded change use that change\'s old value.');

        $withoutReason = AuditLog::query()->where('auditable_type', RecruitmentSetting::class)->where('action', 'updated')->whereNull('reason')
            ->when($firstRecorded !== null, fn ($q) => $q->where('created_at', '>=', $firstRecorded), fn ($q) => $q->whereRaw('1 = 0'))
            ->count();
        $add(self::WARNING, 'audit', 'setting_change_without_reason', $withoutReason, 'Setting changes since the history began that carry no reason (written outside RecruitmentSettingService).');

        $unversioned = RecruitmentPipelineTemplate::query()
            ->whereNotExists(fn ($q) => $q->from('recruitment_pipeline_template_versions')->whereColumn('recruitment_pipeline_template_versions.pipeline_template_id', 'recruitment_pipeline_templates.id')->whereColumn('recruitment_pipeline_template_versions.version', 'recruitment_pipeline_templates.version'))
            ->count();
        $add(self::INFO, 'configuration', 'pipeline_template_version_not_stored', $unversioned, 'Pipeline templates whose current version has no stored definition yet (captured on the next change or application).');

        $unresolvable = RecruitmentRequisition::query()->whereNotNull('pipeline_template_id')
            ->whereNotExists(fn ($q) => $q->from('recruitment_pipeline_template_versions')->whereColumn('recruitment_pipeline_template_versions.pipeline_template_id', 'recruitment_requisitions.pipeline_template_id')->whereColumn('recruitment_pipeline_template_versions.version', 'recruitment_requisitions.pipeline_template_version'))
            ->count();
        $add(self::INFO, 'reproducibility', 'requisition_template_version_unresolvable', $unresolvable, 'Requisitions whose recorded template version was applied before versions were stored (their own pipeline snapshot is still exact).');
    }

    private function dependencies(callable $add): void
    {
        $orphanScope = AutomationRule::query()
            ->whereIn('status', [AutomationRuleStatus::Active, AutomationRuleStatus::Paused, AutomationRuleStatus::Draft])
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $d) => $d->where('scope_type', AutomationScope::Department)->whereNotIn('scope_id', Department::withTrashed()->select('id')))
                ->orWhere(fn (Builder $l) => $l->where('scope_type', AutomationScope::Location)->whereNotIn('scope_id', Location::withTrashed()->select('id'))))
            ->count();
        $add(self::ERROR, 'dependencies', 'automation_scope_missing', $orphanScope, 'Automation rules scoped to a department or location that no longer exists (no database constraint).');

        $blankedSnapshots = HiringOutcomeSnapshot::query()
            ->join('recruitment_requisitions', 'recruitment_requisitions.id', '=', 'hiring_outcome_snapshots.requisition_id')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $d) => $d->whereNull('hiring_outcome_snapshots.department_id')->whereNotNull('recruitment_requisitions.department_id'))
                ->orWhere(fn (Builder $d) => $d->whereNull('hiring_outcome_snapshots.designation_id')->whereNotNull('recruitment_requisitions.designation_id')))
            ->count();
        $add(self::WARNING, 'dependencies', 'snapshot_dimension_blanked', $blankedSnapshots, 'Outcome snapshots with a blank department/designation although their requisition has one — set null by a permanent delete before 8.6. Not repaired.');

        $slabLost = RecruiterIncentiveCalculation::query()->whereNull('incentive_slab_id')
            ->whereIn('incentive_rule_id', RecruitmentIncentiveRule::query()->whereIn('payout_type', [IncentivePayoutType::SlabByCount, IncentivePayoutType::SlabByAchievement])->select('id'))
            ->count();
        $add(self::WARNING, 'dependencies', 'calculation_slab_lost', $slabLost, 'Slab-priced incentive calculations whose slab was deleted before 8.6 (the amount is kept; the band is unknown unless a pricing snapshot exists).');

        $templateLost = Offer::query()->whereNull('offer_letter_template_id')->whereNull('offer_letter_body')
            ->whereIn('status', [OfferStatus::Released, OfferStatus::Accepted])
            ->whereNotExists(fn ($q) => $q->from('offer_letters')->whereColumn('offer_letters.offer_id', 'offers.id'))
            ->count();
        $add(self::INFO, 'dependencies', 'released_offer_without_template_or_letter', $templateLost, 'Released/accepted offers with no chosen template, no custom wording and no issued letter: their letter comes from the current default template.');
    }

    private function effectiveRanges(callable $add): void
    {
        foreach (['recruitment_daily_targets' => RecruitmentDailyTarget::class, 'recruiter_performance_rules' => RecruiterPerformanceRule::class, 'recruitment_incentive_rules' => RecruitmentIncentiveRule::class] as $table => $class) {
            $reversed = $class::query()->whereNotNull('effective_to')->whereColumn('effective_to', '<', 'effective_from')->count();
            $add(self::ERROR, 'effective ranges', "reversed_range_{$table}", $reversed, "{$table}: effective-to before effective-from.");
        }

        $overlap = fn (string $table, array $same): int => (int) DB::table("{$table} as a")
            ->join("{$table} as b", function ($join) use ($same): void {
                $join->on('a.id', '<', 'b.id');

                foreach ($same as $column) {
                    $join->where(fn ($q) => $q->whereColumn("a.{$column}", "b.{$column}")->orWhere(fn ($n) => $n->whereNull("a.{$column}")->whereNull("b.{$column}")));
                }
            })
            ->where(fn ($q) => $q->whereNull('a.effective_to')->orWhereColumn('a.effective_to', '>=', 'b.effective_from'))
            ->where(fn ($q) => $q->whereNull('b.effective_to')->orWhereColumn('b.effective_to', '>=', 'a.effective_from'))
            ->count();

        $add(self::ERROR, 'effective ranges', 'overlapping_targets', $overlap('recruitment_daily_targets', ['employee_id', 'designation_id', 'department_id', 'metric', 'period_type']), 'Pairs of targets for the same scope, metric and period whose dates overlap (created before 8.6).');
        $add(self::ERROR, 'effective ranges', 'overlapping_performance_rules', $overlap('recruiter_performance_rules', ['metric']), 'Pairs of performance rules weighting the same metric on overlapping dates (double-counted before 8.6).');
    }

    private function reproducibility(callable $add): void
    {
        $withoutLetter = Offer::query()->whereIn('status', [OfferStatus::Released, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired])
            ->whereNotExists(fn ($q) => $q->from('offer_letters')->whereColumn('offer_letters.offer_id', 'offers.id'))->count();
        $add(self::INFO, 'reproducibility', 'released_offers_without_issued_letter', $withoutLetter, 'Offers released before issued letters were stored — a download regenerates the letter from current data.');

        $letters = OfferLetter::query()->count();
        $add(self::INFO, 'reproducibility', 'issued_letters', $letters, 'Issued offer letters stored (served as issued, hash-checked).');

        $unpriced = RecruiterIncentiveCalculation::query()->whereNull('pricing_snapshot')->count();
        $add(self::INFO, 'reproducibility', 'calculations_without_pricing_snapshot', $unpriced, 'Incentive calculations priced before 8.6 — their stored amount is authoritative; the rule/slab shown is the current one, marked as such.');

        $olderSnapshots = HiringOutcomeSnapshot::query()->where('rules_version', '!=', HiringOutcomeSnapshot::RULES_VERSION)->count();
        $add(self::INFO, 'reproducibility', 'snapshots_without_frozen_names', $olderSnapshots, 'Hiring snapshots captured before hiring-snapshot/3 — their dimension names follow later renames.');

        $unfrozen = RecruiterPerformanceSnapshot::query()->whereNull('frozen_at')
            ->whereDate('period_end', '<', MetricPeriod::now()->startOfMonth()->toDateString())->count();
        $add(self::WARNING, 'reproducibility', 'completed_months_not_frozen', $unfrozen, 'Performance snapshots of completed months that are not frozen (more than three months back are not caught up automatically).');

        $rejectedCount = RecruitmentRejectionReason::withTrashed()->whereNotNull('deleted_at')->count();
        $add(self::INFO, 'master data', 'archived_reasons', $rejectedCount, 'Archived rejection/dropout reasons — still shown on the records that used them.');
    }
}
