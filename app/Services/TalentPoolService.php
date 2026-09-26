<?php

namespace App\Services;

use App\Enums\TalentPoolMemberSource;
use App\Enums\TalentPoolStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Events\CandidateAddedToTalentPool;
use App\Events\CandidateRemovedFromTalentPool;
use App\Models\Employee;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Talent pool lifecycle and membership (Phase 4). The only writer of talent_pool_memberships.
 * Candidate Master records are never created or modified here — a pool only ever references
 * existing candidates. Each add/remove is audited (Auditable on the membership row), put on the
 * candidate's timeline, and announced with an event after commit.
 *
 * Authorisation (who may see/edit which pool, which candidates a user may add) is enforced by
 * TalentPoolPolicy and the callers' candidate scoping; this service enforces data rules.
 */
class TalentPoolService
{
    public function __construct(private readonly CandidateTimelineService $timeline) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?Employee $actor = null): TalentPool
    {
        return TalentPool::query()->create([
            ...array_intersect_key($data, array_flip(['name', 'description', 'visibility', 'department_id', 'tags', 'criteria'])),
            'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
            'status' => TalentPoolStatus::Active,
            'owner_id' => $data['owner_id'] ?? $actor?->id,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TalentPool $pool, array $data): TalentPool
    {
        $pool->update(array_intersect_key($data, array_flip(['name', 'description', 'visibility', 'owner_id', 'department_id', 'tags', 'criteria'])));

        return $pool;
    }

    public function archive(TalentPool $pool, ?Employee $actor = null): TalentPool
    {
        if (! $pool->isActive()) {
            throw new DomainException('This talent pool is already archived.');
        }

        $pool->forceFill(['status' => TalentPoolStatus::Archived, 'archived_at' => now(), 'archived_by' => $actor?->id])->save();

        return $pool;
    }

    public function restore(TalentPool $pool): TalentPool
    {
        $pool->forceFill(['status' => TalentPoolStatus::Active, 'archived_at' => null, 'archived_by' => null])->save();

        return $pool;
    }

    /**
     * Adds candidates, skipping any already an active member (duplicate membership prevention)
     * and reactivating any previously removed. Returns how many were added and skipped.
     *
     * @param  iterable<int, int|string>  $candidateIds
     * @return array{added: int, skipped: int}
     */
    public function addCandidates(
        TalentPool $pool,
        iterable $candidateIds,
        ?Employee $actor = null,
        TalentPoolMemberSource $source = TalentPoolMemberSource::Manual,
        ?string $reason = null,
        ?string $notes = null,
    ): array {
        $this->ensureActive($pool);

        $ids = collect($candidateIds)->map(fn ($id) => (int) $id)->unique()->values();

        return DB::transaction(function () use ($pool, $ids, $actor, $source, $reason, $notes): array {
            $existing = TalentPoolMembership::query()
                ->where('talent_pool_id', $pool->id)
                ->whereIn('candidate_id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('candidate_id');

            $added = 0;

            foreach ($ids as $candidateId) {
                $membership = $existing->get($candidateId);

                if ($membership?->isActive()) {
                    continue;
                }

                $attributes = [
                    'source' => $source,
                    'reason' => $reason,
                    'notes' => $notes,
                    'added_by' => $actor?->id,
                    'added_at' => now(),
                    'removed_by' => null,
                    'removed_at' => null,
                    'removal_reason' => null,
                ];

                if ($membership !== null) {
                    $membership->update($attributes);
                } else {
                    $membership = TalentPoolMembership::query()->create([...$attributes, 'talent_pool_id' => $pool->id, 'candidate_id' => $candidateId]);
                }

                $this->timeline->record(
                    $candidateId,
                    TimelineEventType::TalentPool,
                    "Added to talent pool \"{$pool->name}\"",
                    $reason,
                    TimelineSource::Recruiter,
                    actor: $actor,
                    related: ['subject' => $pool],
                    metadata: ['talent_pool_id' => $pool->id, 'action' => 'added', 'source' => $source->value],
                );

                CandidateAddedToTalentPool::dispatch($membership, $actor);
                $added++;
            }

            return ['added' => $added, 'skipped' => $ids->count() - $added];
        });
    }

    /**
     * @param  iterable<int, int|string>  $candidateIds
     */
    public function removeCandidates(TalentPool $pool, iterable $candidateIds, ?Employee $actor = null, ?string $reason = null): int
    {
        $ids = collect($candidateIds)->map(fn ($id) => (int) $id)->unique()->values();

        return DB::transaction(function () use ($pool, $ids, $actor, $reason): int {
            $memberships = TalentPoolMembership::query()
                ->where('talent_pool_id', $pool->id)
                ->whereIn('candidate_id', $ids)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->get();

            foreach ($memberships as $membership) {
                $membership->update(['removed_by' => $actor?->id, 'removed_at' => now(), 'removal_reason' => $reason]);

                $this->timeline->record(
                    $membership->candidate_id,
                    TimelineEventType::TalentPool,
                    "Removed from talent pool \"{$pool->name}\"",
                    $reason,
                    TimelineSource::Recruiter,
                    actor: $actor,
                    related: ['subject' => $pool],
                    metadata: ['talent_pool_id' => $pool->id, 'action' => 'removed'],
                );

                CandidateRemovedFromTalentPool::dispatch($membership, $actor);
            }

            return $memberships->count();
        });
    }

    /**
     * Moves active members of $from into $to in one transaction.
     *
     * @param  iterable<int, int|string>  $candidateIds
     * @return array{added: int, skipped: int}
     */
    public function moveCandidates(TalentPool $from, TalentPool $to, iterable $candidateIds, ?Employee $actor = null, ?string $reason = null): array
    {
        if ($from->is($to)) {
            throw new DomainException('Choose a different pool to move candidates to.');
        }

        $ids = collect($candidateIds)->values();

        return DB::transaction(function () use ($from, $to, $ids, $actor, $reason): array {
            $memberIds = $from->activeMemberships()->whereIn('candidate_id', $ids)->pluck('candidate_id');

            $result = $this->addCandidates($to, $memberIds, $actor, TalentPoolMemberSource::Moved, $reason ?? "Moved from {$from->name}");
            $this->removeCandidates($from, $memberIds, $actor, $reason ?? "Moved to {$to->name}");

            return $result;
        });
    }

    private function ensureActive(TalentPool $pool): void
    {
        if (! $pool->isActive()) {
            throw new DomainException("The talent pool \"{$pool->name}\" is archived; restore it before adding candidates.");
        }
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'talent-pool';
        $slug = $base;
        $suffix = 2;

        while (TalentPool::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
