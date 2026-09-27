<?php

namespace App\Services;

use App\Enums\RecruiterActionStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Filament\Resources\RecruiterActions\RecruiterActionResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\Automation\RecipientResolver;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only writer of Action Center items (Phase 6). Every status change and reassignment is
 * audited; completion is also written to the candidate's internal timeline. Ownership follows the
 * hierarchy: an item is visible to its owner and everyone above them (HierarchyService), and an
 * item is never left with an inactive owner — it moves to their nearest active manager.
 */
class RecruiterActionService
{
    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly NotificationDispatchService $notifications,
        private readonly CandidateTimelineService $timeline,
        private readonly RecipientResolver $recipients,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?Employee $actor = null, bool $notifyOwner = true): RecruiterAction
    {
        $owner = $this->reachableOwner(Employee::query()->with('user')->find($attributes['owner_id'] ?? null));

        if ($owner === null) {
            throw new DomainException('This action has no active owner to assign it to.');
        }

        $action = RecruiterAction::query()->create([...$attributes, 'owner_id' => $owner->id, 'created_by' => $attributes['created_by'] ?? $actor?->id]);

        AuditLog::record($action, 'recruiter_action_created', null, [
            'title' => $action->title,
            'owner_id' => $action->owner_id,
            'priority' => $action->priority->value,
            'automation_rule_id' => $action->automation_rule_id,
        ]);

        if ($notifyOwner) {
            $this->notifyOwner($action, $owner);
        }

        return $action;
    }

    /**
     * Idempotent creation for automation: the same dedupe key never creates a second item.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOnce(string $dedupeKey, array $attributes): RecruiterAction
    {
        $existing = RecruiterAction::query()->where('dedupe_key', $dedupeKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create([...$attributes, 'dedupe_key' => $dedupeKey]);
        } catch (UniqueConstraintViolationException) {
            return RecruiterAction::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }

    public function start(RecruiterAction $action, Employee $actor): RecruiterAction
    {
        $this->guardOpen($action);

        $action->forceFill(['status' => RecruiterActionStatus::InProgress, 'started_at' => now()])->save();
        AuditLog::record($action, 'recruiter_action_started', ['status' => RecruiterActionStatus::Open->value], ['status' => $action->status->value, 'by' => $actor->id]);

        return $action;
    }

    public function complete(RecruiterAction $action, Employee $actor, ?string $note = null): RecruiterAction
    {
        $this->guardOpen($action);
        $previous = $action->status;

        $action->forceFill([
            'status' => RecruiterActionStatus::Completed,
            'completed_at' => now(),
            'completed_by' => $actor->id,
            'resolution_note' => $note,
        ])->save();

        AuditLog::record($action, 'recruiter_action_completed', ['status' => $previous->value], ['status' => $action->status->value, 'by' => $actor->id, 'note' => $note]);

        if ($action->candidate_id !== null) {
            $this->timeline->record(
                $action->candidate_id,
                TimelineEventType::RecruiterAction,
                "Action completed: {$action->title}",
                $note,
                TimelineSource::Recruiter,
                TimelineVisibility::Internal,
                $actor,
                ['application' => $action->candidateApplication, 'subject' => $action],
            );
        }

        return $action;
    }

    public function dismiss(RecruiterAction $action, Employee $actor, string $reason): RecruiterAction
    {
        $this->guardOpen($action);

        if (blank($reason)) {
            throw new DomainException('A reason is required to dismiss an action.');
        }

        $previous = $action->status;
        $action->forceFill(['status' => RecruiterActionStatus::Dismissed, 'completed_at' => now(), 'completed_by' => $actor->id, 'resolution_note' => $reason])->save();
        AuditLog::record($action, 'recruiter_action_dismissed', ['status' => $previous->value], ['status' => $action->status->value, 'by' => $actor->id, 'reason' => $reason]);

        return $action;
    }

    /**
     * Only someone who can see both the item and the new owner in their hierarchy may reassign.
     */
    public function reassign(RecruiterAction $action, Employee $newOwner, User $actor, string $reason): RecruiterAction
    {
        $this->guardOpen($action);

        if (! $actor->can('actions.manage') || ! $this->hierarchy->canView($actor, $newOwner) || ($action->owner !== null && ! $this->hierarchy->canView($actor, $action->owner))) {
            throw new DomainException('You can only reassign actions within your own team.');
        }

        if (! $this->recipients->isReachable($newOwner)) {
            throw new DomainException('The new owner is inactive or has no login.');
        }

        return $this->moveTo($action, $newOwner, $reason, $actor->employee_id);
    }

    /**
     * Open items whose owner can no longer be reached — inactive, separated or deleted, or whose
     * login is suspended, revoked or missing — move to the nearest reachable manager (audited).
     * Phase 8.4: previously only status Inactive was caught.
     */
    public function reassignFromInactiveOwners(?int $ownerId = null): int
    {
        $moved = 0;

        RecruiterAction::query()
            ->open()
            ->when($ownerId !== null, fn (Builder $query) => $query->where('owner_id', $ownerId))
            ->with('owner.user')
            ->chunkById(200, function ($actions) use (&$moved) {
                foreach ($actions as $action) {
                    if ($this->recipients->isReachable($action->owner)) {
                        continue;
                    }

                    $manager = $this->reachableOwner($action->owner) ?? $this->fallbackOwner();

                    if ($manager !== null && $manager->id !== $action->owner_id) {
                        $this->moveTo($action, $manager, 'Previous owner is no longer active', null);
                        $moved++;
                    }
                }
            });

        return $moved;
    }

    /**
     * Phase 8.4: the final fallback when nobody in the chain is reachable — the first reachable
     * holder of identity.handoff_fallback_permission (an HR administrator).
     */
    public function fallbackOwner(): ?Employee
    {
        return User::query()
            ->whereNotNull('employee_id')
            ->where('access_status', 'active')
            ->with('employee.user')
            ->orderBy('id')
            ->get()
            ->first(fn (User $user) => $user->can((string) config('identity.handoff_fallback_permission')) && $this->recipients->isReachable($user->employee))
            ?->employee;
    }

    /**
     * Open items past their due date by more than $graceDays become Expired (audited).
     */
    public function expireOverdue(int $graceDays): int
    {
        $expired = 0;

        RecruiterAction::query()
            ->open()
            ->where('due_at', '<', now()->subDays($graceDays))
            ->chunkById(200, function ($actions) use (&$expired) {
                foreach ($actions as $action) {
                    $previous = $action->status;
                    $action->forceFill(['status' => RecruiterActionStatus::Expired, 'completed_at' => now()])->save();
                    AuditLog::record($action, 'recruiter_action_expired', ['status' => $previous->value], ['status' => RecruiterActionStatus::Expired->value]);
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Items the user may see: their own and their team's (everything with hierarchy.view-all).
     *
     * @return Builder<RecruiterAction>
     */
    public function visibleTo(User $user): Builder
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

        return RecruiterAction::query()->when($visible !== null, fn (Builder $query) => $query->whereIn('owner_id', $visible));
    }

    /**
     * The given employee if they are active with a login, otherwise their nearest active manager.
     */
    public function reachableOwner(?Employee $employee): ?Employee
    {
        if ($employee === null) {
            return null;
        }

        if ($this->recipients->isReachable($employee)) {
            return $employee;
        }

        return $this->hierarchy->managementChainOf($employee->id)->first(fn (Employee $manager) => $this->recipients->isReachable($manager));
    }

    private function moveTo(RecruiterAction $action, Employee $newOwner, string $reason, ?int $byEmployeeId): RecruiterAction
    {
        $previousOwner = $action->owner_id;
        $action->forceFill(['owner_id' => $newOwner->id])->save();

        AuditLog::record($action, 'recruiter_action_reassigned', ['owner_id' => $previousOwner], ['owner_id' => $newOwner->id, 'reason' => $reason, 'by' => $byEmployeeId]);
        $this->notifyOwner($action, $newOwner->loadMissing('user'));

        return $action;
    }

    private function notifyOwner(RecruiterAction $action, Employee $owner): void
    {
        $this->notifications->alert(
            $owner->user,
            'Action Center',
            $action->title,
            $action->reason ?? 'A new action was assigned to you.',
            $action->priority->notificationColor(),
            RecruiterActionResource::getUrl('index'),
            "recruiter-action-{$action->id}-owner-{$owner->id}",
            $action->priority,
            array_filter([
                'rule_id' => $action->automation_rule_id,
                'execution_id' => $action->automation_execution_id,
                'entity_type' => 'recruiter_action',
                'entity_id' => $action->id,
            ]),
        );
    }

    private function guardOpen(RecruiterAction $action): void
    {
        if (! $action->isOpen()) {
            throw new DomainException("This action is already {$action->status->label()}.");
        }
    }
}
