<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'offer_code',
    'candidate_application_id',
    'designation_id',
    'location_id',
    'offered_ctc',
    'fixed_salary',
    'variable_salary',
    'joining_bonus',
    'offer_date',
    'offer_expiry',
    'status',
    'accepted_at',
    'expected_joining_date',
    'remarks',
    'created_by',
])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'offered_ctc' => 'decimal:2',
            'fixed_salary' => 'decimal:2',
            'variable_salary' => 'decimal:2',
            'joining_bonus' => 'decimal:2',
            'offer_date' => 'date',
            'offer_expiry' => 'date',
            'accepted_at' => 'datetime',
            'expected_joining_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @return HasMany<OfferStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OfferStatusHistory::class)->latest('created_at');
    }

    /**
     * @return HasOne<CandidateJoining, $this>
     */
    public function joining(): HasOne
    {
        return $this->hasOne(CandidateJoining::class);
    }
}
