<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Observers\CandidateObserver;
use App\Services\CandidateIdentityNormalizer;
use App\Services\HierarchyService;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'candidate_code',
    'full_name',
    'mobile',
    'alternate_mobile',
    'email',
    'location',
    'current_city',
    'qualification',
    'total_experience',
    'relevant_experience',
    'current_company',
    'current_designation',
    'current_salary',
    'expected_salary',
    'notice_period_days',
    'skills',
    'resume_path',
    'source_id',
    'source_details',
    'referral_employee_id',
    'remarks',
    'created_by',
])]
#[ObservedBy(CandidateObserver::class)]
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Derived duplicate-detection keys: kept out of serialisation and (via getHidden()) out of
     * audit diffs, since they only ever change alongside the real identifier.
     *
     * @var array<int, string>
     */
    protected $hidden = ['mobile_normalized', 'alternate_mobile_normalized', 'email_normalized', 'name_normalized'];

    protected static function booted(): void
    {
        static::saving(function (Candidate $candidate): void {
            $candidate->forceFill([
                'mobile_normalized' => CandidateIdentityNormalizer::mobile($candidate->mobile),
                'alternate_mobile_normalized' => CandidateIdentityNormalizer::mobile($candidate->alternate_mobile),
                'email_normalized' => CandidateIdentityNormalizer::email($candidate->email),
                'name_normalized' => CandidateIdentityNormalizer::name($candidate->full_name),
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'total_experience' => 'decimal:1',
            'relevant_experience' => 'decimal:1',
            'current_salary' => 'decimal:2',
            'expected_salary' => 'decimal:2',
        ];
    }

    /**
     * Candidates a user may see: all with hierarchy.view-all; otherwise those with an application
     * owned by someone in the user's hierarchy, or created by the user. The one definition of
     * candidate visibility — the Filament resource and EDGE Intelligence both use it.
     *
     * @param  Builder<Candidate>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return;
        }

        $query->where(function (Builder $q) use ($visibleIds, $user): void {
            $q->whereHas('applications', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds));

            if ($user->employee_id !== null) {
                $q->orWhere('created_by', $user->employee_id);
            }
        });
    }

    /**
     * @return BelongsTo<CandidateSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CandidateSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function referralEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'referral_employee_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @return HasMany<CandidateApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CandidateApplication::class);
    }

    /**
     * Every document held for this candidate — attached before joining (e.g. a resume) or from
     * their joining record (CandidateDocument fills candidate_id from the joining on create).
     *
     * @return HasMany<CandidateDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }

    /**
     * @return HasMany<CandidateCommunication, $this>
     */
    public function communications(): HasMany
    {
        return $this->hasMany(CandidateCommunication::class)->latest();
    }

    /**
     * @return HasMany<CandidateCommunicationPreference, $this>
     */
    public function communicationPreferences(): HasMany
    {
        return $this->hasMany(CandidateCommunicationPreference::class);
    }

    /**
     * The candidate's portal login, when invited (Phase 4).
     *
     * @return HasOne<CandidatePortalAccount, $this>
     */
    public function portalAccount(): HasOne
    {
        return $this->hasOne(CandidatePortalAccount::class);
    }

    /**
     * @return HasMany<TalentPoolMembership, $this>
     */
    public function talentPoolMemberships(): HasMany
    {
        return $this->hasMany(TalentPoolMembership::class);
    }

    /**
     * Talent pools the candidate currently belongs to.
     *
     * @return BelongsToMany<TalentPool, $this>
     */
    public function talentPools(): BelongsToMany
    {
        return $this->belongsToMany(TalentPool::class, 'talent_pool_memberships')->wherePivotNull('removed_at');
    }

    /**
     * @return HasMany<CandidateTimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(CandidateTimelineEvent::class)->latest('occurred_at');
    }

    /**
     * @return HasMany<CandidateDuplicateMatch, $this>
     */
    public function duplicateMatches(): HasMany
    {
        return $this->hasMany(CandidateDuplicateMatch::class, 'candidate_id');
    }

    /**
     * Set once this candidate has been converted to an employee (Section 44).
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
}
