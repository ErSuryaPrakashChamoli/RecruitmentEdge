<?php

namespace App\Models;

use App\Enums\RoleDnaOrigin;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Immutable Role DNA snapshot. `dna` is the list of attributes
 * {key, category, label, value, level, origin, active, note}; each attribute's evidence is in
 * intelligence_evidence with subject_key = the attribute key.
 */
#[Fillable(['role_dna_profile_id', 'version', 'dna', 'generator', 'generator_version', 'ai_model', 'change_summary', 'created_by'])]
class RoleDnaVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Role DNA versions are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'dna' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * Attributes that drive signals: everything except unconfirmed AI suggestions and anything a
     * person rejected.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function effectiveAttributes(): Collection
    {
        return collect($this->dna ?? [])
            ->filter(fn (array $attribute) => ($attribute['active'] ?? true) && ($attribute['origin'] ?? null) !== RoleDnaOrigin::AiSuggestion->value)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingSuggestions(): Collection
    {
        return collect($this->dna ?? [])
            ->filter(fn (array $attribute) => ($attribute['active'] ?? true) && ($attribute['origin'] ?? null) === RoleDnaOrigin::AiSuggestion->value)
            ->values();
    }

    /**
     * @return BelongsTo<RoleDnaProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(RoleDnaProfile::class, 'role_dna_profile_id');
    }

    /**
     * @return MorphMany<IntelligenceEvidence, $this>
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(IntelligenceEvidence::class, 'owner');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
