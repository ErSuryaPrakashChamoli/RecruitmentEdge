<?php

namespace App\Services;

use App\Enums\IncentiveCalculationStatus;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.5 (SEC-4, D37/D42): the only way a recruiter activity is logged, corrected or removed.
 * Activities feed call targets, performance scores and incentive slabs, so every write answers:
 *
 * - who created it — the acting login's employee, always set here (created_by), never taken from input;
 * - for whom — the actor themselves or someone in their hierarchy (HierarchyService::canView);
 * - for which date — today back to `activity_backdate_days` days ago (business timezone), never in
 *   the future;
 * - under which authority — the `activities.log` permission;
 * - with which correction rule — an activity whose day falls in a period with an approved (or
 *   payable / paid) incentive calculation for that recruiter can no longer be edited or deleted; a
 *   correction there is an audited incentive adjustment instead;
 * - whether it can affect incentive pay — only activities accepted by these rules exist to count.
 *
 * Every write is audited with the request correlation id.
 */
class RecruitmentActivityService
{
    /**
     * @var array<int, IncentiveCalculationStatus>
     */
    public const array LOCKING_STATUSES = [IncentiveCalculationStatus::Approved, IncentiveCalculationStatus::Payable, IncentiveCalculationStatus::Paid];

    public function __construct(private readonly HierarchyService $hierarchy) {}

    public static function backdateDays(): int
    {
        return max(0, (int) RecruitmentSetting::get('activity_backdate_days', 7));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function log(User $actor, array $data): RecruitmentDailyActivity
    {
        $attributes = $this->validated($actor, $data);

        return DB::transaction(function () use ($actor, $attributes): RecruitmentDailyActivity {
            $activity = RecruitmentDailyActivity::query()->create([...$attributes, 'created_by' => $actor->employee_id]);

            AuditLog::record($activity, 'activity_logged', null, $this->auditValues($activity));

            return $activity;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, RecruitmentDailyActivity $activity, array $data): RecruitmentDailyActivity
    {
        $this->ensureCorrectable($actor, $activity);
        $attributes = $this->validated($actor, [...Arr::only($activity->getAttributes(), ['recruiter_id', 'candidate_id', 'candidate_application_id', 'activity_type', 'activity_datetime', 'outcome', 'remarks']), ...$data]);
        $this->ensureNotLocked((int) $attributes['recruiter_id'], CarbonImmutable::parse($attributes['activity_datetime']));

        return DB::transaction(function () use ($activity, $attributes): RecruitmentDailyActivity {
            $before = $this->auditValues($activity);
            $activity->fill($attributes)->save();

            AuditLog::record($activity, 'activity_corrected', $before, $this->auditValues($activity));

            return $activity;
        });
    }

    public function delete(User $actor, RecruitmentDailyActivity $activity): void
    {
        $this->ensureCorrectable($actor, $activity);

        DB::transaction(function () use ($activity): void {
            AuditLog::record($activity, 'activity_deleted', $this->auditValues($activity), null);
            $activity->delete();
        });
    }

    /**
     * The recruiters the actor may log activity for: themselves and their hierarchy.
     *
     * @return Builder<Employee>
     */
    public function recruitersFor(User $actor): Builder
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($actor);

        return Employee::query()->when($visible !== null, fn ($q) => $q->whereIn('id', $visible));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(User $actor, array $data): array
    {
        if (! $actor->can('activities.log')) {
            throw new DomainException('You are not allowed to log recruiter activity.');
        }

        $recruiter = Employee::query()->find($data['recruiter_id'] ?? null);

        if ($recruiter === null || ! $this->hierarchy->canView($actor, $recruiter)) {
            throw new DomainException('You can log activity only for yourself or someone in your team.');
        }

        if (blank($data['activity_datetime'] ?? null)) {
            throw new DomainException('The activity date and time is required.');
        }

        $at = CarbonImmutable::parse($data['activity_datetime']);
        $day = MetricPeriod::businessDate($at);
        $today = MetricPeriod::now();
        $earliest = $today->subDays(self::backdateDays())->toDateString();

        if ($at->gt(now()->addMinutes(5))) {
            throw new DomainException('An activity cannot be logged for a time that has not happened yet.');
        }

        if ($day < $earliest) {
            throw new DomainException('Activity can be logged at most '.self::backdateDays().' day(s) back.');
        }

        if (filled($data['candidate_application_id'] ?? null)) {
            $application = CandidateApplication::query()->find($data['candidate_application_id']);

            if ($application === null || ! $this->hierarchy->canView($actor, $application->recruiter)) {
                throw new DomainException('Choose an application you can see.');
            }

            if (filled($data['candidate_id'] ?? null) && (int) $data['candidate_id'] !== (int) $application->candidate_id) {
                throw new DomainException('The application does not belong to the selected candidate.');
            }

            $data['candidate_id'] = $application->candidate_id;
        } elseif (filled($data['candidate_id'] ?? null) && ! Candidate::query()->visibleTo($actor)->whereKey($data['candidate_id'])->exists()) {
            // Phase 8.10 (P810-SEC-004): a candidate on its own must be one the actor can see.
            throw new DomainException('Choose a candidate you can see.');
        }

        return [
            'recruiter_id' => $recruiter->id,
            'candidate_id' => $data['candidate_id'] ?? null,
            'candidate_application_id' => $data['candidate_application_id'] ?? null,
            'activity_type' => $data['activity_type'] ?? null,
            'activity_datetime' => $at,
            'outcome' => $data['outcome'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    private function ensureCorrectable(User $actor, RecruitmentDailyActivity $activity): void
    {
        if (! $actor->can('activities.log') || ! $this->hierarchy->canView($actor, $activity->recruiter)) {
            throw new DomainException('You can correct activity only for yourself or someone in your team.');
        }

        $this->ensureNotLocked((int) $activity->recruiter_id, CarbonImmutable::instance($activity->activity_datetime));
    }

    /**
     * An activity in a period whose incentive for that recruiter is approved (or paid) is locked.
     */
    private function ensureNotLocked(int $recruiterId, CarbonImmutable $at): void
    {
        $day = MetricPeriod::businessDate($at);

        $locked = RecruiterIncentiveCalculation::query()
            ->where('employee_id', $recruiterId)
            ->whereIn('status', array_map(fn (IncentiveCalculationStatus $s) => $s->value, self::LOCKING_STATUSES))
            ->whereDate('period_start', '<=', $day)
            ->whereDate('period_end', '>=', $day)
            ->exists();

        if ($locked) {
            throw new DomainException('This activity falls in a period whose incentive is already approved; correct it with an incentive adjustment instead.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(RecruitmentDailyActivity $activity): array
    {
        return [
            'recruiter_id' => $activity->recruiter_id,
            'candidate_application_id' => $activity->candidate_application_id,
            'activity_type' => $activity->activity_type?->value,
            'activity_datetime' => $activity->activity_datetime?->toIso8601String(),
            'outcome' => $activity->outcome?->value,
            'created_by' => $activity->created_by,
        ];
    }
}
