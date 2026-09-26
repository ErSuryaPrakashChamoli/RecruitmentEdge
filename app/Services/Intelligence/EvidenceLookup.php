<?php

namespace App\Services\Intelligence;

use App\Models\HiringHealthSnapshot;
use App\Models\HiringMemoryRecord;
use App\Models\HiringRisk;
use App\Models\IntelligenceEvidence;
use App\Models\RecruitmentRequisition;
use App\Models\RediscoveryResult;
use App\Models\RoleDnaVersion;
use App\Models\TalentSignalSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Loads the evidence behind one intelligence value for display, after checking the viewer may see
 * the record it belongs to (the requisition it is about). Owner types are whitelisted, so a
 * tampered request cannot read evidence of anything else.
 */
class EvidenceLookup
{
    /**
     * @var array<string, class-string>
     */
    public const array OWNERS = [
        'role_dna_version' => RoleDnaVersion::class,
        'talent_signal' => TalentSignalSnapshot::class,
        'hiring_health' => HiringHealthSnapshot::class,
        'hiring_risk' => HiringRisk::class,
        'rediscovery_result' => RediscoveryResult::class,
        'hiring_memory' => HiringMemoryRecord::class,
    ];

    /**
     * @return Collection<int, IntelligenceEvidence>|null null when not found or not visible
     */
    public function for(User $user, string $ownerAlias, int $ownerId, ?string $subjectKey = null): ?Collection
    {
        $class = self::OWNERS[$ownerAlias] ?? null;
        $owner = $class !== null ? $class::query()->find($ownerId) : null;

        if ($owner === null || ! $this->canSee($user, $owner)) {
            return null;
        }

        return $owner->evidence()
            ->when($subjectKey !== null, fn ($q) => $q->where('subject_key', $subjectKey))
            ->with(['source', 'verifiedBy:id,name'])
            ->orderBy('id')
            ->limit(100)
            ->get();
    }

    private function canSee(User $user, object $owner): bool
    {
        if (! $user->can('intelligence.view')) {
            return false;
        }

        if ($owner instanceof HiringRisk || $owner instanceof HiringMemoryRecord) {
            return $user->can('view', $owner);
        }

        $requisitionId = match (true) {
            $owner instanceof RoleDnaVersion => $owner->profile?->requisition_id,
            $owner instanceof RediscoveryResult => $owner->run?->requisition_id,
            default => $owner->requisition_id,
        };

        return $requisitionId !== null && RecruitmentRequisition::query()->visibleTo($user)->whereKey($requisitionId)->exists();
    }
}
