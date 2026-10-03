<?php

namespace App\Services\Intelligence;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Enums\RediscoveryResultStatus;
use App\Enums\SignalBand;
use App\Enums\TalentPoolMemberSource;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRequisition;
use App\Models\RediscoveryResult;
use App\Models\RediscoveryRun;
use App\Models\TalentPool;
use App\Models\User;
use App\Services\CandidateTimelineService;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Intelligence\Data\EvidenceItem;
use App\Services\SequenceCodeGenerator;
use App\Services\TalentPoolService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Talent Rediscovery™ (Phase 7): finds people the organisation already knows — candidates the user
 * may see plus members of talent pools the user may see — who align with a requisition's current
 * Role DNA, and explains why with evidence. Deterministic: every candidate is scored by the same
 * TalentSignalCalculator, bounded to the most recently active `max_scan` candidates.
 *
 * Excludes people already applied to this requisition and people already hired. Flags (not
 * hides) people who opted out of every channel. Nothing about a candidate changes until a person
 * adds them to the requisition or a pool — through the existing paths — or dismisses them.
 */
class TalentRediscoveryService
{
    public const string RULES_VERSION = 'rediscovery/1';

    public function __construct(
        private readonly RoleDnaService $roleDna,
        private readonly TalentSignalCalculator $calculator,
        private readonly EvidenceRecorder $evidence,
        private readonly CommunicationPreferenceService $preferences,
    ) {}

    public function run(RecruitmentRequisition $requisition, User $actor): RediscoveryRun
    {
        $dna = $this->roleDna->currentVersionFor($requisition, $actor);
        $maxScan = (int) config('intelligence.rediscovery.max_scan', 2000);
        $scanned = 0;
        $scored = collect();

        $this->universe($requisition, $actor)
            ->with(TalentSignalCalculator::RELATIONS)
            ->orderByDesc('updated_at')
            ->limit($maxScan)
            ->get()
            ->each(function (Candidate $candidate) use ($dna, $requisition, &$scanned, $scored): void {
                $scanned++;
                $result = $this->calculator->calculate($candidate, $dna, $requisition);

                if (in_array($result->band, [SignalBand::Strong, SignalBand::Moderate], true)) {
                    $scored->push([$candidate, $result]);
                }
            });

        $top = $scored
            ->sortBy(fn (array $row) => [$row[1]->band === SignalBand::Strong ? 0 : 1, -($row[1]->coverage ?? 0), -$row[1]->completeness])
            ->take((int) config('intelligence.rediscovery.max_results', 25))
            ->values();

        $run = DB::transaction(function () use ($requisition, $dna, $actor, $scanned, $top): RediscoveryRun {
            $run = RediscoveryRun::query()->create([
                'requisition_id' => $requisition->id,
                'role_dna_version_id' => $dna->id,
                'run_by' => $actor->id,
                'status' => 'completed',
                'rules_version' => self::RULES_VERSION,
                'candidates_scanned' => $scanned,
                'results_count' => $top->count(),
            ]);

            foreach ($top as $rank => [$candidate, $result]) {
                $row = RediscoveryResult::query()->create([
                    'rediscovery_run_id' => $run->id,
                    'candidate_id' => $candidate->id,
                    'rank' => $rank + 1,
                    'band' => $result->band,
                    'required_coverage_pct' => $result->coverage,
                    'summary' => ['reasons' => $this->reasons($candidate, $result->reasons), 'components' => $result->components],
                    'do_not_contact' => $this->doNotContact($candidate),
                ]);

                $this->evidence->record($row, [...$result->evidence, ...$this->contextEvidence($candidate)], 'talent-rediscovery', self::RULES_VERSION);
            }

            return $run;
        });

        AuditLog::record($run, 'talent_rediscovery_run', null, ['requisition_id' => $requisition->id, 'role_dna_version' => $dna->version, 'scanned' => $scanned, 'results' => $top->count(), 'by_user_id' => $actor->id]);

        return $run;
    }

    /**
     * Adds the candidate to the requisition as a new Sourced application — the same way the career
     * site creates applications — and records it on the candidate timeline.
     */
    public function addToRequisition(RediscoveryResult $result, User $actor): CandidateApplication
    {
        $this->guardSuggested($result);
        $this->guardWithinReach($result, $actor);
        $requisition = $result->run->requisition;

        if (CandidateApplication::query()->where('candidate_id', $result->candidate_id)->where('requisition_id', $requisition->id)->exists()) {
            throw new DomainException('This candidate has already been added to the requisition.');
        }

        return DB::transaction(function () use ($result, $actor, $requisition): CandidateApplication {
            $application = CandidateApplication::query()->create([
                'application_code' => app(SequenceCodeGenerator::class)->next('APP'),
                'candidate_id' => $result->candidate_id,
                'requisition_id' => $requisition->id,
                'recruiter_id' => $actor->employee_id ?? $requisition->recruiters()->value('employees.id'),
                'current_stage' => CandidateStage::Sourced,
                'application_date' => now()->toDateString(),
                'status' => ApplicationStatus::Active,
                'origin_channel' => 'rediscovery',
                'remarks' => "Added from Talent Rediscovery run #{$result->rediscovery_run_id}",
            ]);

            app(CandidateTimelineService::class)->record(
                $result->candidate_id,
                TimelineEventType::SystemEvent,
                "Rediscovered for {$requisition->code}",
                'Added from Talent Rediscovery by '.$actor->name.'.',
                TimelineSource::Recruiter,
                actor: $actor,
                related: ['application' => $application],
            );

            $this->markActioned($result, RediscoveryResultStatus::AddedToRequisition, $actor, "Application {$application->application_code}");

            return $application;
        });
    }

    public function addToPool(RediscoveryResult $result, TalentPool $pool, User $actor): RediscoveryResult
    {
        $this->guardSuggested($result);

        if (! $actor->can('addMembers', $pool)) {
            throw new DomainException('You cannot add candidates to this talent pool.');
        }

        $this->guardWithinReach($result, $actor);

        app(TalentPoolService::class)->addCandidates($pool, [$result->candidate_id], $actor->employee, TalentPoolMemberSource::Rediscovery, "Talent Rediscovery for {$result->run->requisition->code}");

        return $this->markActioned($result, RediscoveryResultStatus::AddedToPool, $actor, "Added to pool {$pool->name}");
    }

    public function dismiss(RediscoveryResult $result, User $actor, string $note): RediscoveryResult
    {
        $this->guardSuggested($result);

        if (blank($note)) {
            throw new DomainException('A note is required to dismiss a suggestion.');
        }

        return $this->markActioned($result, RediscoveryResultStatus::Dismissed, $actor, $note);
    }

    /**
     * Candidates the user may see, plus members of talent pools the user may see — minus people
     * already on this requisition and people already hired (employee record or a joined
     * application).
     *
     * @return Builder<Candidate>
     */
    public function universe(RecruitmentRequisition $requisition, User $user): Builder
    {
        return $this->withinReach($user)
            ->whereDoesntHave('applications', fn (Builder $a) => $a->where('requisition_id', $requisition->id))
            // Already hired: converted to an employee, or joined through any requisition.
            ->whereDoesntHave('employee')
            ->whereDoesntHave('applications', fn (Builder $a) => $a->whereIn('current_stage', RecruitmentRequisition::filledStageValues())->where('status', ApplicationStatus::Active));
    }

    /**
     * Candidates the user may see, plus current members of talent pools the user may see.
     *
     * @return Builder<Candidate>
     */
    private function withinReach(User $user): Builder
    {
        $visiblePools = TalentPool::query()->visibleTo($user)->select('id');

        return Candidate::query()
            ->where(fn (Builder $q) => $q
                ->whereIn('candidates.id', Candidate::query()->visibleTo($user)->select('candidates.id'))
                ->orWhereHas('talentPoolMemberships', fn (Builder $m) => $m->whereNull('removed_at')->whereIn('talent_pool_id', $visiblePools)));
    }

    /**
     * Phase 8.10 (P810-SEC-004): a requisition's latest run is shown to everyone who can open its
     * intelligence page, but it was drawn from the reach of whoever ran it. Acting on a suggestion
     * (adding to the requisition or a pool) needs the candidate to be within the actor's own
     * reach, so a run by a wider-scoped colleague never brings anyone else into the actor's scope.
     */
    private function guardWithinReach(RediscoveryResult $result, User $actor): void
    {
        if (! $this->withinReach($actor)->whereKey($result->candidate_id)->exists()) {
            throw new DomainException('This candidate is outside your team\'s candidates and talent pools. Ask whoever ran the rediscovery to add them.');
        }
    }

    private function doNotContact(Candidate $candidate): bool
    {
        return collect(CommunicationChannel::sendable())->every(fn (CommunicationChannel $channel) => $this->preferences->statusFor($candidate, $channel) === PreferenceStatus::OptedOut);
    }

    /**
     * @param  array<int, string>  $signalReasons
     * @return array<int, string>
     */
    private function reasons(Candidate $candidate, array $signalReasons): array
    {
        return collect($signalReasons)
            ->push($candidate->talentPools->isNotEmpty() ? 'In talent pool(s): '.$candidate->talentPools->pluck('name')->implode(', ').'.' : null)
            ->push($candidate->referral_employee_id !== null ? 'Originally an employee referral.' : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, EvidenceItem>
     */
    private function contextEvidence(Candidate $candidate): array
    {
        return $candidate->talentPools->map(fn (TalentPool $pool) => EvidenceItem::fact('pools', 'Member of talent pool', $pool->name, $pool))->values()->all();
    }

    private function markActioned(RediscoveryResult $result, RediscoveryResultStatus $status, User $actor, string $note): RediscoveryResult
    {
        $result->forceFill(['status' => $status, 'actioned_by' => $actor->id, 'actioned_at' => now(), 'action_note' => mb_substr($note, 0, 250)])->save();
        AuditLog::record($result, 'rediscovery_result_actioned', null, ['status' => $status->value, 'candidate_id' => $result->candidate_id, 'note' => $note, 'by_user_id' => $actor->id]);

        return $result;
    }

    private function guardSuggested(RediscoveryResult $result): void
    {
        if ($result->status !== RediscoveryResultStatus::Suggested) {
            throw new DomainException("This suggestion was already handled ({$result->status->label()}).");
        }
    }

    /**
     * @return Collection<int, RediscoveryRun>
     */
    public function recentRuns(RecruitmentRequisition $requisition, int $limit = 5): Collection
    {
        return RediscoveryRun::query()->where('requisition_id', $requisition->id)->with('runBy:id,name')->latest('id')->limit($limit)->get();
    }
}
