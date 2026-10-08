<?php

namespace App\Services\Intelligence;

use App\Enums\EvidenceType;
use App\Enums\RequirementLevel;
use App\Enums\RoleDnaCategory;
use App\Enums\RoleDnaOrigin;
use App\Enums\RoleDnaStatus;
use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\IntelligenceEvidence;
use App\Models\RecruitmentRequisition;
use App\Models\RoleDnaProfile;
use App\Models\RoleDnaVersion;
use App\Models\User;
use App\Services\Intelligence\Data\EvidenceItem;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of Role DNA™ (Phase 7). Every change produces a new immutable RoleDnaVersion with
 * its evidence: a rebuild from the requisition (keeping everything people decided), AI suggestions
 * (unconfirmed), a person confirming or rejecting an attribute, or adding one. Talent Signals and
 * rediscovery runs record the version they used, so history stays attributable. Audited.
 */
class RoleDnaService
{
    public function __construct(
        private readonly RoleDnaBuilder $builder,
        private readonly EvidenceRecorder $evidence,
    ) {}

    public function profileFor(RecruitmentRequisition $requisition): RoleDnaProfile
    {
        return RoleDnaProfile::query()->firstOrCreate(
            ['requisition_id' => $requisition->id],
            ['designation_id' => $requisition->designation_id],
        );
    }

    /**
     * The current version, building the first one if the requisition has none yet.
     */
    public function currentVersionFor(RecruitmentRequisition $requisition, ?User $actor = null): RoleDnaVersion
    {
        $profile = $this->profileFor($requisition);

        return $profile->currentVersion ?? $this->rebuild($requisition, $actor, 'Initial build from the requisition');
    }

    /**
     * Regenerates configured, inferred and historical attributes from the requisition and Hiring
     * Memory, keeping every attribute a person added, confirmed or rejected, and pending AI
     * suggestions.
     */
    public function rebuild(RecruitmentRequisition $requisition, ?User $actor = null, string $summary = 'Rebuilt from the requisition'): RoleDnaVersion
    {
        $profile = $this->profileFor($requisition);
        $built = $this->builder->build($requisition);
        $previous = $profile->currentVersion;

        $kept = collect($previous?->dna ?? [])->filter(fn (array $attribute) => in_array($attribute['origin'], [RoleDnaOrigin::HumanConfirmed->value, RoleDnaOrigin::AiSuggestion->value], true) || ! ($attribute['active'] ?? true));
        $keptKeys = $kept->pluck('key')->all();
        $attributes = [...collect($built['attributes'])->reject(fn (array $attribute) => in_array($attribute['key'], $keptKeys, true))->all(), ...$kept->values()->all()];

        $evidence = $built['evidence'];

        foreach ($kept as $attribute) {
            $evidence[$attribute['key']] = $this->carriedEvidence($previous, $attribute['key']);
        }

        if ($previous !== null && $this->fingerprint($attributes) === $this->fingerprint($previous->dna)) {
            return $previous;
        }

        return $this->newVersion($profile, $attributes, $evidence, $actor, $summary, RoleDnaBuilder::GENERATOR, RoleDnaBuilder::VERSION);
    }

    /**
     * Adds (or replaces) an attribute a person entered — immediately human-confirmed.
     *
     * @param  array{category: string, label: string, value?: string|null, level: string}  $data
     */
    public function addAttribute(RecruitmentRequisition $requisition, array $data, User $actor): RoleDnaVersion
    {
        $category = RoleDnaCategory::from($data['category']);
        $level = RequirementLevel::from($data['level']);
        $label = trim($data['label']);

        if ($label === '') {
            throw new DomainException('An attribute needs a name.');
        }

        $key = $category === RoleDnaCategory::Skill ? IntelligenceText::skillKey($label) : $category->value.':'.Str::slug($label);
        $current = $this->currentVersionFor($requisition, $actor);

        $attributes = collect($current->dna)->reject(fn (array $attribute) => $attribute['key'] === $key)->push([
            'key' => $key,
            'category' => $category->value,
            'label' => $label,
            'value' => $data['value'] ?? $label,
            'level' => $level->value,
            'origin' => RoleDnaOrigin::HumanConfirmed->value,
            'active' => true,
            'note' => null,
            'data' => [],
        ])->values()->all();

        $evidence = $this->carryAll($current, $attributes);
        $evidence[$key] = [EvidenceItem::confirmation($key, "Added by {$actor->name}", $label, $actor)];

        $version = $this->newVersion($this->profileFor($requisition), $attributes, $evidence, $actor, "Attribute added: {$label}", 'human', '1');
        AuditLog::record($version->profile, 'role_dna_attribute_added', null, ['key' => $key, 'level' => $level->value, 'version' => $version->version]);

        return $version;
    }

    /**
     * A person accepts an AI suggestion (or re-confirms any attribute): it becomes human-confirmed.
     */
    public function confirmAttribute(RecruitmentRequisition $requisition, string $key, User $actor): RoleDnaVersion
    {
        $current = $this->currentVersionFor($requisition, $actor);
        $attribute = collect($current->dna)->firstWhere('key', $key) ?? throw new DomainException('Unknown Role DNA attribute.');
        $wasAi = $attribute['origin'] === RoleDnaOrigin::AiSuggestion->value;

        if ($wasAi) {
            $this->markAiEvidence($current, $key, $actor, VerificationStatus::Verified);
        }

        $attributes = collect($current->dna)->map(fn (array $a) => $a['key'] === $key ? [...$a, 'origin' => RoleDnaOrigin::HumanConfirmed->value, 'active' => true, 'note' => $wasAi ? 'Suggested by AI, confirmed by a person' : $a['note']] : $a)->all();
        $evidence = $this->carryAll($current, $attributes);
        $evidence[$key] = [...$evidence[$key], EvidenceItem::confirmation($key, "Confirmed by {$actor->name}", $attribute['label'], $actor)];

        $version = $this->newVersion($this->profileFor($requisition), $attributes, $evidence, $actor, "Attribute confirmed: {$attribute['label']}", 'human', '1');
        AuditLog::record($version->profile, 'role_dna_attribute_confirmed', ['origin' => $attribute['origin']], ['key' => $key, 'version' => $version->version]);

        return $version;
    }

    /**
     * A person rejects an attribute (typically an AI suggestion): kept for history, no longer used.
     */
    public function rejectAttribute(RecruitmentRequisition $requisition, string $key, User $actor, string $reason): RoleDnaVersion
    {
        if (blank($reason)) {
            throw new DomainException('A reason is required to reject an attribute.');
        }

        $current = $this->currentVersionFor($requisition, $actor);
        $attribute = collect($current->dna)->firstWhere('key', $key) ?? throw new DomainException('Unknown Role DNA attribute.');

        if ($attribute['origin'] === RoleDnaOrigin::AiSuggestion->value) {
            $this->markAiEvidence($current, $key, $actor, VerificationStatus::Rejected);
        }

        $attributes = collect($current->dna)->map(fn (array $a) => $a['key'] === $key ? [...$a, 'active' => false, 'note' => "Rejected by {$actor->name}: {$reason}"] : $a)->all();

        $version = $this->newVersion($this->profileFor($requisition), $attributes, $this->carryAll($current, $attributes), $actor, "Attribute rejected: {$attribute['label']}", 'human', '1');
        AuditLog::record($version->profile, 'role_dna_attribute_rejected', null, ['key' => $key, 'reason' => $reason, 'version' => $version->version]);

        return $version;
    }

    /**
     * Records validated AI suggestions as unconfirmed attributes in a new version.
     *
     * @param  array<int, array{category: string, label: string, level: string, reason: string|null}>  $suggestions
     */
    public function applyAiSuggestions(RoleDnaProfile $profile, array $suggestions, string $model): ?RoleDnaVersion
    {
        $current = $profile->currentVersion ?? $this->rebuild($profile->requisition);
        $existing = collect($current->dna)->pluck('key')->all();
        $attributes = collect($current->dna);
        $evidence = $this->carryAll($current, $current->dna);
        $added = 0;

        foreach ($suggestions as $suggestion) {
            $category = RoleDnaCategory::from($suggestion['category']);
            $key = $category === RoleDnaCategory::Skill ? IntelligenceText::skillKey($suggestion['label']) : $category->value.':'.Str::slug($suggestion['label']);

            if (in_array($key, $existing, true) || $this->alreadyCovered($attributes, $category, $suggestion['label'])) {
                continue;
            }

            $existing[] = $key;
            $attributes->push([
                'key' => $key,
                'category' => $category->value,
                'label' => $suggestion['label'],
                'value' => $suggestion['label'],
                'level' => $suggestion['level'],
                'origin' => RoleDnaOrigin::AiSuggestion->value,
                'active' => true,
                'note' => 'AI suggestion — not used until a person confirms it.',
                'data' => [],
            ]);
            $evidence[$key] = [EvidenceItem::ai($key, 'Suggested by AI from the role description', $suggestion['label'], $model, $suggestion['reason'])];
            $added++;
        }

        if ($added === 0) {
            return null;
        }

        return $this->newVersion($profile, $attributes->values()->all(), $evidence, null, "{$added} AI suggestion(s) added (unconfirmed)", 'ai:role-dna-suggestions', '1', $model);
    }

    public function confirmProfile(RecruitmentRequisition $requisition, User $actor): RoleDnaProfile
    {
        $current = $this->currentVersionFor($requisition, $actor);

        if ($current->pendingSuggestions()->isNotEmpty()) {
            throw new DomainException('Confirm or reject every AI suggestion before confirming the Role DNA.');
        }

        $profile = $this->profileFor($requisition);
        $profile->forceFill(['status' => RoleDnaStatus::Confirmed, 'confirmed_by' => $actor->id, 'confirmed_at' => now()])->save();
        AuditLog::record($profile, 'role_dna_confirmed', null, ['version' => $current->version, 'by_user_id' => $actor->id]);

        return $profile;
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @param  array<string, array<int, EvidenceItem>>  $evidence
     */
    private function newVersion(RoleDnaProfile $profile, array $attributes, array $evidence, ?User $actor, string $summary, string $generator, string $generatorVersion, ?string $aiModel = null): RoleDnaVersion
    {
        return DB::transaction(function () use ($profile, $attributes, $evidence, $actor, $summary, $generator, $generatorVersion, $aiModel): RoleDnaVersion {
            $profile = RoleDnaProfile::query()->lockForUpdate()->findOrFail($profile->id);

            $version = RoleDnaVersion::query()->create([
                'role_dna_profile_id' => $profile->id,
                'version' => $profile->current_version + 1,
                'dna' => array_values($attributes),
                'generator' => $generator,
                'generator_version' => $generatorVersion,
                'ai_model' => $aiModel,
                'change_summary' => $summary,
                'created_by' => $actor?->id,
            ]);

            foreach ($evidence as $items) {
                $this->evidence->record($version, $items, $generator, $generatorVersion);
            }

            // A changed Role DNA needs a person's confirmation again.
            $profile->forceFill(['current_version' => $version->version, 'status' => RoleDnaStatus::Draft, 'confirmed_by' => null, 'confirmed_at' => null])->save();
            AuditLog::record($profile, 'role_dna_version_created', null, ['version' => $version->version, 'summary' => $summary, 'attributes' => count($attributes)]);

            return $version;
        });
    }

    /**
     * Evidence of carried-over attributes is re-recorded against the new version (evidence is owned
     * by exactly one version and never edited).
     *
     * @param  array<int, array<string, mixed>>  $attributes
     * @return array<string, array<int, EvidenceItem>>
     */
    private function carryAll(RoleDnaVersion $from, array $attributes): array
    {
        return collect($attributes)->mapWithKeys(fn (array $attribute) => [$attribute['key'] => $this->carriedEvidence($from, $attribute['key'])])->all();
    }

    /**
     * @return array<int, EvidenceItem>
     */
    private function carriedEvidence(?RoleDnaVersion $from, string $key): array
    {
        if ($from === null) {
            return [];
        }

        return $from->evidence()->where('subject_key', $key)->get()->map(fn (IntelligenceEvidence $row) => new EvidenceItem(
            $row->evidence_type,
            $row->label,
            $row->value,
            $key,
            $row->numeric_value,
            $row->source,
            $row->observed_at,
            $row->explanation,
            $row->confidence,
            $row->ai_model,
            $row->verification_status,
        ))->all();
    }

    private function markAiEvidence(RoleDnaVersion $version, string $key, User $actor, VerificationStatus $status): void
    {
        $version->evidence()
            ->where('subject_key', $key)
            ->where('evidence_type', EvidenceType::AiInference)
            ->where('verification_status', VerificationStatus::Unverified)
            ->get()
            ->each(fn (IntelligenceEvidence $row) => app(EvidenceRecorder::class)->verify($row, $actor, $status));
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     */
    private function fingerprint(array $attributes): string
    {
        return md5(json_encode(collect($attributes)->map(fn (array $a) => [$a['key'], $a['value'], $a['level'], $a['origin'], $a['active'] ?? true])->sortBy(0)->values()));
    }

    /**
     * Required skills in effect for signals (configured + human-confirmed, not rejected).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function skills(RoleDnaVersion $version, RequirementLevel $level): Collection
    {
        return $version->effectiveAttributes()->filter(fn (array $a) => $a['category'] === RoleDnaCategory::Skill->value && $a['level'] === $level->value)->values();
    }

    /**
     * A suggested skill that just restates an existing one ("PHP Programming" when "PHP" is
     * already in the DNA) adds nothing but review work, so it is not stored.
     *
     * @param  Collection<int, array<string, mixed>>  $attributes
     */
    private function alreadyCovered(Collection $attributes, RoleDnaCategory $category, string $label): bool
    {
        return $category === RoleDnaCategory::Skill
            && $attributes->contains(fn (array $a) => $a['category'] === RoleDnaCategory::Skill->value && IntelligenceText::containsPhrase($label, (string) $a['label']));
    }
}
