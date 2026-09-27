<?php

namespace App\Services\Intelligence;

use App\Enums\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRequisition;
use App\Models\TalentSignalSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Persists Talent Signal™ snapshots (Phase 7). A snapshot is recomputed only when it is stale — the
 * candidate or application changed, the Role DNA moved to a new version, or the rules changed — and
 * a recomputation supersedes the previous snapshot instead of editing it, so what a recruiter saw
 * at the time stays reproducible.
 */
class TalentSignalService
{
    public const string GENERATOR = 'talent-signal';

    public function __construct(
        private readonly TalentSignalCalculator $calculator,
        private readonly RoleDnaService $roleDna,
        private readonly EvidenceRecorder $evidence,
    ) {}

    public function currentFor(CandidateApplication $application): ?TalentSignalSnapshot
    {
        return TalentSignalSnapshot::query()
            ->where('candidate_id', $application->candidate_id)
            ->where('requisition_id', $application->requisition_id)
            ->where('is_current', true)
            ->latest('id')
            ->first();
    }

    public function refresh(CandidateApplication $application, ?User $actor = null, bool $force = false): TalentSignalSnapshot
    {
        $application->loadMissing(['candidate', 'requisition']);
        $dna = $this->roleDna->currentVersionFor($application->requisition, $actor);
        $current = $this->currentFor($application);

        if (! $force && $current !== null && ! $this->isStale($current, $application)) {
            return $current;
        }

        $result = $this->calculator->calculate($application->candidate, $dna, $application->requisition, $application);

        $snapshot = DB::transaction(function () use ($application, $dna, $result): TalentSignalSnapshot {
            // Phase 8.7 (D8.7-026): concurrent refreshes take turns on the application row, so only
            // one signal is ever current.
            CandidateApplication::query()->whereKey($application->id)->lockForUpdate()->first();

            TalentSignalSnapshot::query()
                ->where('candidate_id', $application->candidate_id)
                ->where('requisition_id', $application->requisition_id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $snapshot = TalentSignalSnapshot::query()->create([
                'candidate_id' => $application->candidate_id,
                'candidate_application_id' => $application->id,
                'requisition_id' => $application->requisition_id,
                'role_dna_version_id' => $dna->id,
                'rules_version' => TalentSignalCalculator::RULES_VERSION,
                'band' => $result->band,
                'required_skills' => $result->requiredSkills,
                'required_skills_matched' => $result->requiredMatched,
                'required_coverage_pct' => $result->coverage,
                'experience_fit' => $result->experienceFit,
                'completeness_pct' => $result->completeness,
                'evidence_count' => count($result->evidence),
                'components' => ['items' => $result->components, 'reasons' => $result->reasons],
                'is_current' => true,
                'computed_at' => now(),
            ]);

            $this->evidence->record($snapshot, $result->evidence, self::GENERATOR, TalentSignalCalculator::RULES_VERSION);

            return $snapshot;
        });

        if ($actor !== null) {
            AuditLog::record($snapshot, 'talent_signal_refreshed', null, ['application_id' => $application->id, 'band' => $result->band->value, 'role_dna_version' => $dna->version, 'by_user_id' => $actor->id]);
        }

        return $snapshot;
    }

    /**
     * Refreshes stale signals for a requisition's active applications (bounded).
     */
    public function refreshForRequisition(RecruitmentRequisition $requisition, int $limit = 200, ?User $actor = null): int
    {
        $refreshed = 0;

        CandidateApplication::query()
            ->where('requisition_id', $requisition->id)
            ->where('status', ApplicationStatus::Active)
            ->with(['candidate', 'requisition'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (CandidateApplication $application) use (&$refreshed): void {
                $before = $this->currentFor($application)?->id;
                $after = $this->refresh($application)->id;
                $refreshed += $before !== $after ? 1 : 0;
            });

        if ($actor !== null && $refreshed > 0) {
            AuditLog::record($requisition, 'talent_signals_refreshed', null, ['refreshed' => $refreshed, 'by_user_id' => $actor->id]);
        }

        return $refreshed;
    }

    public function isStale(TalentSignalSnapshot $snapshot, ?CandidateApplication $application = null): bool
    {
        $application ??= $snapshot->candidateApplication;
        $profile = $this->roleDna->profileFor($application->requisition);

        return $snapshot->rules_version !== TalentSignalCalculator::RULES_VERSION
            || $snapshot->roleDnaVersion?->version !== $profile->current_version
            || $application->candidate->updated_at?->gt($snapshot->computed_at)
            || $application->updated_at?->gt($snapshot->computed_at);
    }
}
