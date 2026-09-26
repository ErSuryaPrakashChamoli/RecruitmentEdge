<?php

namespace App\Services\Intelligence;

use App\Enums\FeedbackRecommendation;
use App\Enums\RequirementLevel;
use App\Enums\SignalBand;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\InterviewFeedback;
use App\Models\RecruitmentRequisition;
use App\Models\RoleDnaVersion;
use App\Services\Intelligence\Data\EvidenceItem;
use App\Services\Intelligence\Data\TalentSignalResult;

/**
 * Talent Signal™ rules `talent-signal/1` (Phase 7): a deterministic, decomposed comparison of one
 * candidate with one Role DNA version. No AI and no single opaque score — each component states
 * what was compared, the outcome and its evidence, and the band is derived from named rules:
 *
 * - insufficient_evidence: data completeness below 40%;
 * - strong: required-skill coverage ≥ 80% and experience not below the range;
 * - weak: required-skill coverage < 40%, or experience more than 2 years below the minimum;
 * - moderate: everything else (including roles with no required skills configured).
 *
 * Fairness: only job-relevant, candidate-stated or recorded facts are used. No name, photo, age,
 * gender or other protected attribute (none are stored, none may be inferred); the interview
 * "culture fit" rating is excluded; location, compensation, notice, history and interviews are
 * shown as context and never change the band. Free-text comparisons that do not match exactly
 * are "unknown", not a mismatch.
 */
class TalentSignalCalculator
{
    public const string RULES_VERSION = 'talent-signal/1';

    public const float MIN_COMPLETENESS = 40.0;

    /**
     * Relations the calculator reads — eager-load them when scoring many candidates.
     *
     * @var array<int, string>
     */
    public const array RELATIONS = ['source', 'applications.interviews.feedback', 'applications.requisition.designation', 'talentPools'];

    public function calculate(Candidate $candidate, RoleDnaVersion $dna, RecruitmentRequisition $requisition, ?CandidateApplication $application = null): TalentSignalResult
    {
        $candidate->loadMissing(self::RELATIONS);
        $evidence = [];
        $components = [];
        $applicable = 0;
        $present = 0;

        // Skills
        $required = RoleDnaService::skills($dna, RequirementLevel::Required);
        $preferred = RoleDnaService::skills($dna, RequirementLevel::Preferred);
        $candidateSkills = collect($candidate->skills ?? [])->map(fn ($skill) => IntelligenceText::normalize((string) $skill))->filter()->unique();
        $coverage = null;
        $matchedRequired = collect();

        if ($required->isNotEmpty() || $preferred->isNotEmpty()) {
            $applicable++;
            $present += $candidateSkills->isNotEmpty() ? 1 : 0;
            $matchedRequired = $required->filter(fn (array $a) => $candidateSkills->contains(IntelligenceText::normalize($a['label'])));
            $missingRequired = $required->reject(fn (array $a) => $candidateSkills->contains(IntelligenceText::normalize($a['label'])));
            $matchedPreferred = $preferred->filter(fn (array $a) => $candidateSkills->contains(IntelligenceText::normalize($a['label'])));
            $coverage = $required->isNotEmpty() && $candidateSkills->isNotEmpty() ? round($matchedRequired->count() / $required->count() * 100, 1) : null;

            $components['skills'] = [
                'label' => 'Skills',
                'status' => match (true) {
                    $candidateSkills->isEmpty() => 'unknown',
                    $coverage === null => 'context',
                    $coverage >= 80 => 'match',
                    $coverage >= 40 => 'partial',
                    default => 'gap',
                },
                'summary' => $candidateSkills->isEmpty()
                    ? 'No skills recorded on the candidate profile.'
                    : ($required->isNotEmpty() ? "{$matchedRequired->count()} of {$required->count()} required skills" : 'No required skills configured').($preferred->isNotEmpty() ? ", {$matchedPreferred->count()} of {$preferred->count()} preferred" : '').'.',
                'matched' => $matchedRequired->pluck('label')->merge($matchedPreferred->pluck('label'))->values()->all(),
                'missing' => $missingRequired->pluck('label')->values()->all(),
            ];

            foreach ($matchedRequired as $skill) {
                $evidence[] = EvidenceItem::fact('skills', "Has required skill: {$skill['label']}", $skill['label'], $candidate, null, 'Listed on the candidate profile (exact tag match).');
            }

            foreach ($missingRequired as $skill) {
                $evidence[] = EvidenceItem::fact('skills', "Required skill not on profile: {$skill['label']}", $skill['label'], $candidate, null, 'Tag matching is literal — the candidate may have it under another name.');
            }

            foreach ($matchedPreferred as $skill) {
                $evidence[] = EvidenceItem::fact('skills', "Has preferred skill: {$skill['label']}", $skill['label'], $candidate);
            }
        }

        // Experience
        $range = $dna->effectiveAttributes()->firstWhere('key', 'experience:range');
        $experienceFit = 'unknown';

        if ($range !== null) {
            $applicable++;
            $years = $candidate->total_experience !== null ? (float) $candidate->total_experience : null;
            $min = $range['data']['min'] ?? null;
            $max = $range['data']['max'] ?? null;

            if ($years !== null) {
                $present++;
                $experienceFit = match (true) {
                    $min !== null && $years < $min => 'below',
                    $max !== null && $years > $max => 'above',
                    default => 'within',
                };
            }

            $components['experience'] = [
                'label' => 'Experience',
                'status' => match ($experienceFit) {
                    'within' => 'match',
                    'above' => 'context',
                    'below' => ($min - $years) > 2 ? 'gap' : 'partial',
                    default => 'unknown',
                },
                'summary' => $years === null ? 'Total experience not recorded.' : "{$years} years against {$range['value']} ({$experienceFit} range).",
            ];
            $evidence[] = EvidenceItem::fact('experience', 'Stated total experience', $years !== null ? "{$years} years" : 'not recorded', $candidate, null, null, $years);
            $evidence[] = EvidenceItem::configured('experience', 'Required experience', $range['value'], $requisition);
        }

        if ($candidate->relevant_experience !== null) {
            $components['relevant_experience'] = ['label' => 'Relevant experience', 'status' => 'context', 'summary' => "{$candidate->relevant_experience} years stated as relevant."];
            $evidence[] = EvidenceItem::fact('relevant_experience', 'Stated relevant experience', "{$candidate->relevant_experience} years", $candidate, null, null, (float) $candidate->relevant_experience);
        }

        // Education
        $qualification = $dna->effectiveAttributes()->firstWhere('key', 'education:qualification');

        if ($qualification !== null) {
            $applicable++;
            $present += filled($candidate->qualification) ? 1 : 0;
            $matches = IntelligenceText::containsPhrase($candidate->qualification, $qualification['value']);
            $components['education'] = [
                'label' => 'Education',
                'status' => $matches ? 'match' : 'unknown',
                'summary' => blank($candidate->qualification)
                    ? 'Qualification not recorded.'
                    : ($matches ? "Stated qualification includes \"{$qualification['value']}\"." : "Stated \"{$candidate->qualification}\" — not an exact match; a person should check equivalence."),
            ];
            $evidence[] = EvidenceItem::fact('education', 'Stated qualification', $candidate->qualification ?? 'not recorded', $candidate);
        }

        // Location (context — never changes the band)
        $location = $dna->effectiveAttributes()->firstWhere('key', 'location:primary');

        if ($location !== null) {
            $applicable++;
            $city = $candidate->current_city ?: $candidate->location;
            $present += filled($city) ? 1 : 0;
            $same = filled($city) && IntelligenceText::normalize((string) $city) === IntelligenceText::normalize((string) $location['value']);
            $components['location'] = [
                'label' => 'Location',
                'status' => blank($city) ? 'unknown' : ($same ? 'match' : 'context'),
                'summary' => blank($city) ? 'Current city not recorded.' : ($same ? "Based in {$location['value']}." : "Based in {$city}; role is in {$location['value']} (relocation not assumed either way)."),
            ];
            $evidence[] = EvidenceItem::fact('location', 'Stated current city', $city ?: 'not recorded', $candidate);
        }

        // Compensation (context)
        $budget = $dna->effectiveAttributes()->firstWhere('key', 'compensation:range');

        if ($budget !== null) {
            $applicable++;
            $expected = $candidate->expected_salary !== null ? (float) $candidate->expected_salary : null;
            $present += $expected !== null ? 1 : 0;
            $max = $budget['data']['max'] ?? null;
            $components['compensation'] = [
                'label' => 'Compensation',
                'status' => $expected === null ? 'unknown' : (($max === null || $expected <= $max) ? 'match' : 'context'),
                'summary' => $expected === null ? 'Expected salary not recorded.' : (($max === null || $expected <= $max) ? 'Expectation within the budget range.' : 'Expectation above the budget range.'),
            ];
            $evidence[] = EvidenceItem::fact('compensation', 'Expected salary vs budget', $expected === null ? 'not recorded' : (($max === null || $expected <= $max) ? 'within budget' : 'above budget'), $candidate);
        }

        // Notice period vs target joining date (context)
        if ($requisition->target_joining_date !== null && $candidate->notice_period_days !== null) {
            $daysToTarget = (int) now()->startOfDay()->diffInDays($requisition->target_joining_date, false);
            $fits = $candidate->notice_period_days <= max(0, $daysToTarget);
            $components['notice'] = [
                'label' => 'Notice period',
                'status' => $fits ? 'match' : 'context',
                'summary' => "{$candidate->notice_period_days} days' notice; target joining in {$daysToTarget} days.",
            ];
            $evidence[] = EvidenceItem::fact('notice', 'Stated notice period', "{$candidate->notice_period_days} days", $candidate, null, null, (float) $candidate->notice_period_days);
        }

        // History, interviews, source, engagement (context only)
        $others = $candidate->applications->reject(fn (CandidateApplication $a) => $a->id === $application?->id)->values();

        if ($others->isNotEmpty()) {
            $furthest = $others->sortByDesc(fn (CandidateApplication $a) => $a->current_stage->order())->first();
            $components['history'] = [
                'label' => 'Previous applications',
                'status' => 'context',
                'summary' => "{$others->count()} other application(s); furthest reached: {$furthest->current_stage->label()}.",
            ];

            foreach ($others->take(5) as $other) {
                $evidence[] = EvidenceItem::fact('history', "Applied for {$other->requisition?->designation?->name} ({$other->requisition?->code})", "{$other->current_stage->label()} · {$other->status->label()}", $other, $other->updated_at);
            }
        }

        $feedback = $candidate->applications->flatMap(fn (CandidateApplication $a) => $a->interviews->flatMap->feedback);

        if ($feedback->isNotEmpty()) {
            $criteria = collect(InterviewFeedback::RATING_CRITERIA)->except(RoleDnaBuilder::EXCLUDED_CRITERIA);
            $averages = $criteria->mapWithKeys(fn (string $label, string $key) => [$label => $feedback->map(fn (InterviewFeedback $f) => $f->ratings[$key] ?? null)->filter()->avg()])->filter();
            $positive = $feedback->filter(fn (InterviewFeedback $f) => in_array($f->recommendation, [FeedbackRecommendation::Recommend, FeedbackRecommendation::StronglyRecommend], true))->count();

            $components['interviews'] = [
                'label' => 'Past interview feedback',
                'status' => 'context',
                'summary' => "{$feedback->count()} feedback entr".($feedback->count() === 1 ? 'y' : 'ies').", {$positive} recommending".($averages->isNotEmpty() ? '; averages '.$averages->map(fn ($avg, $label) => "{$label} ".round($avg, 1).'/5')->implode(', ') : '').'. (Culture fit is not used.)',
            ];

            foreach ($feedback->take(5) as $entry) {
                $evidence[] = EvidenceItem::interviewer('interviews', 'Interview feedback', trim(($entry->recommendation?->label() ?? 'No recommendation').' · '.collect($entry->ratings ?? [])->except(RoleDnaBuilder::EXCLUDED_CRITERIA)->map(fn ($v, $k) => "{$k} {$v}/5")->implode(', ')), $entry, $entry->score !== null ? (float) $entry->score : null);
            }
        }

        $context = collect([
            $candidate->source?->name ? "Source: {$candidate->source->name}" : null,
            $candidate->referral_employee_id !== null ? 'Employee referral' : null,
            $candidate->talentPools->isNotEmpty() ? 'Talent pools: '.$candidate->talentPools->pluck('name')->implode(', ') : null,
        ])->filter();

        if ($context->isNotEmpty()) {
            $components['source'] = ['label' => 'Source & pools', 'status' => 'context', 'summary' => $context->implode(' · ').'.'];
            $evidence[] = EvidenceItem::fact('source', 'Source context', $context->implode(' · '), $candidate);
        }

        $lastActivity = $candidate->applications->max('last_activity_at');

        if ($lastActivity !== null) {
            $days = (int) $lastActivity->diffInDays(now());
            $components['engagement'] = ['label' => 'Recent activity', 'status' => 'context', 'summary' => "Last activity {$days} day(s) ago."];
            $evidence[] = EvidenceItem::fact('engagement', 'Last recorded activity', $lastActivity->toDateString(), null, $lastActivity, null, (float) $days);
        }

        $completeness = $applicable > 0 ? round($present / $applicable * 100, 1) : 0.0;
        $belowBy = $range !== null && $experienceFit === 'below' ? (($range['data']['min'] ?? 0) - (float) $candidate->total_experience) : 0;

        $band = match (true) {
            $completeness < self::MIN_COMPLETENESS => SignalBand::InsufficientEvidence,
            ($coverage !== null && $coverage < 40) || $belowBy > 2 => SignalBand::Weak,
            $coverage !== null && $coverage >= 80 && $experienceFit !== 'below' => SignalBand::Strong,
            default => SignalBand::Moderate,
        };

        return new TalentSignalResult(
            band: $band,
            components: $components,
            evidence: $evidence,
            requiredSkills: $required->count(),
            requiredMatched: $matchedRequired->count(),
            coverage: $coverage,
            experienceFit: $experienceFit,
            completeness: $completeness,
            reasons: $this->reasons($band, $components, $completeness),
        );
    }

    /**
     * Short, human "why" lines for the band — each one points at a component.
     *
     * @param  array<string, array<string, mixed>>  $components
     * @return array<int, string>
     */
    private function reasons(SignalBand $band, array $components, float $completeness): array
    {
        if ($band === SignalBand::InsufficientEvidence) {
            return ["Only {$completeness}% of the facts needed for comparison are recorded."];
        }

        return collect(['skills', 'experience', 'education', 'interviews', 'history'])
            ->filter(fn (string $key) => isset($components[$key]))
            ->map(fn (string $key) => $components[$key]['label'].': '.$components[$key]['summary'])
            ->values()
            ->all();
    }
}
