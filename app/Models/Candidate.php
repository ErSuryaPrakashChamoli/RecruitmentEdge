<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Observers\CandidateObserver;
use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
