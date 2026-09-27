<?php

namespace App\Services;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationScope;
use App\Enums\EmployeeStatus;
use App\Enums\RequisitionStatus;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 8.6 master-data lifecycle (D8.6-001/002/003): the only way departments, designations,
 * locations, candidate sources and rejection/dropout reasons leave (or return to) service.
 *
 * - Deactivate: always allowed (except a source the application itself relies on); the record
 *   stays on history but can no longer be chosen. Needs a reason.
 * - Archive (soft delete): refused while open work still uses the record — the refusal lists
 *   what. Needs a reason. History keeps resolving the archived record.
 * - Restore: brings an archived record back as Inactive; reactivating is a separate, deliberate
 *   step. Needs a reason.
 * - Activate: no reason needed.
 * - Never a permanent delete (GovernedMasterData refuses it).
 *
 * Every change is audited with the actor and the reason (AuditLog::withReason).
 */
class MasterDataLifecycleService
{
    /**
     * @var array<int, class-string<Model>>
     */
    public const array GOVERNED = [Department::class, Designation::class, Location::class, CandidateSource::class, RecruitmentRejectionReason::class];

    /**
     * Requisition statuses that are still open work.
     *
     * @var array<int, RequisitionStatus>
     */
    public const array OPEN_REQUISITION_STATUSES = [RequisitionStatus::Draft, RequisitionStatus::PendingApproval, RequisitionStatus::Approved, RequisitionStatus::Open, RequisitionStatus::OnHold];

    public function deactivate(User $actor, Model $record, ?string $reason): Model
    {
        $this->authorize($actor, 'update', $record);
        $reason = $this->requireReason($reason, 'deactivating');

        if (($protection = $this->protection($record)) !== null) {
            throw new DomainException($protection);
        }

        AuditLog::withReason($reason, fn () => $record->forceFill(['is_active' => false])->save());

        return $record;
    }

    public function activate(User $actor, Model $record, ?string $reason = null): Model
    {
        $this->authorize($actor, 'update', $record);

        if ($record->trashed()) {
            throw new DomainException('Restore the archived record before activating it.');
        }

        AuditLog::withReason($reason, fn () => $record->forceFill(['is_active' => true])->save());

        return $record;
    }

    public function archive(User $actor, Model $record, ?string $reason): Model
    {
        $this->authorize($actor, 'delete', $record);
        $reason = $this->requireReason($reason, 'archiving');

        if (($protection = $this->protection($record)) !== null) {
            throw new DomainException($protection);
        }

        if (($blockers = $this->openWork($record)) !== []) {
            throw new DomainException('Still in use — '.collect($blockers)->map(fn (int $count, string $what) => "{$count} {$what}")->implode(', ').'. Deactivate it instead, or move that work first.');
        }

        DB::transaction(fn () => AuditLog::withReason($reason, function () use ($record): void {
            if ($record->is_active) {
                $record->forceFill(['is_active' => false])->save();
            }

            $record->delete();
        }));

        return $record;
    }

    public function restore(User $actor, Model $record, ?string $reason): Model
    {
        $this->authorize($actor, 'restore', $record);
        $reason = $this->requireReason($reason, 'restoring');

        if (! $record->trashed()) {
            throw new DomainException('Only an archived record can be restored.');
        }

        AuditLog::withReason($reason, fn () => $record->restore());

        return $record;
    }

    /**
     * Open work that still uses the record, as "what" => count (only non-zero entries). Closed
     * and historical records never block: they keep resolving the archived record.
     *
     * @return array<string, int>
     */
    public function openWork(Model $record): array
    {
        $id = $record->getKey();
        $today = today()->toDateString();
        $current = fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));

        $checks = match ($record::class) {
            Department::class, Designation::class, Location::class => [
                'open requisitions' => fn () => RecruitmentRequisition::query()->where($this->column($record), $id)->whereIn('status', self::OPEN_REQUISITION_STATUSES)->count(),
                'active employees' => fn () => Employee::query()->where($this->column($record), $id)->where('status', EmployeeStatus::Active)->count(),
                'active incentive rules' => fn () => $current(RecruitmentIncentiveRule::query()->where($this->column($record), $id)->where('is_active', true))->count(),
                'current targets' => fn () => $record instanceof Location ? 0 : $current(RecruitmentDailyTarget::query()->where($this->column($record), $id))->count(),
                'active automation rules' => fn () => $record instanceof Designation ? 0 : AutomationRule::query()
                    ->where('scope_type', $record instanceof Department ? AutomationScope::Department : AutomationScope::Location)
                    ->where('scope_id', $id)
                    ->whereIn('status', [AutomationRuleStatus::Active, AutomationRuleStatus::Paused])
                    ->count(),
            ],
            default => [],
        };

        return collect($checks)->map(fn (callable $count) => $count())->filter()->all();
    }

    /**
     * Why the record may not be deactivated or archived at all, or null.
     */
    public function protection(Model $record): ?string
    {
        if ($record instanceof CandidateSource && in_array($record->code, CandidateSource::SYSTEM_CODES, true)) {
            return "{$record->name} is used by the application itself (career site / referrals) and cannot be deactivated or archived.";
        }

        return null;
    }

    private function column(Model $record): string
    {
        return match ($record::class) {
            Department::class => 'department_id',
            Designation::class => 'designation_id',
            Location::class => 'location_id',
        };
    }

    private function authorize(User $actor, string $ability, Model $record): void
    {
        if (! in_array($record::class, self::GOVERNED, true)) {
            throw new DomainException('Not governed master data.');
        }

        if (! Gate::forUser($actor)->allows($ability, $record)) {
            throw new AuthorizationException('You are not allowed to change this master data.');
        }
    }

    private function requireReason(?string $reason, string $doing): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new DomainException("A reason is required for {$doing} master data.");
        }

        return mb_substr($reason, 0, 1000);
    }
}
