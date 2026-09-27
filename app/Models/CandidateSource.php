<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\GovernedMasterData;
use Database\Factories\CandidateSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'code', 'is_active'])]
class CandidateSource extends Model
{
    /** @use HasFactory<CandidateSourceFactory> */
    use Auditable, GovernedMasterData, HasFactory, SoftDeletes;

    /**
     * Phase 8.6 (D8.6-007): the application finds its own sources by code, never by name, so a
     * rename in Administration can't silently change attribution. Seeded by
     * RecruitmentReferenceDataSeeder.
     */
    public const string CODE_WEBSITE = 'SRC-006';

    public const string CODE_EMPLOYEE_REFERRAL = 'SRC-007';

    /**
     * Sources the application relies on: they can never be deactivated or archived.
     *
     * @var array<int, string>
     */
    public const array SYSTEM_CODES = [self::CODE_WEBSITE, self::CODE_EMPLOYEE_REFERRAL];

    /**
     * An active source by its code, or null.
     */
    public static function activeByCode(string $code): ?self
    {
        return self::query()->where('code', $code)->where('is_active', true)->first();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class, 'source_id');
    }
}
