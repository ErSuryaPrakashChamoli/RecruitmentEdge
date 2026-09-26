<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GuardsLifecycleAttributes;
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
    'offer_letter_template_id',
    'offer_letter_body',
    'created_by',
])]
class Offer extends Model
{
    use Auditable, GuardsLifecycleAttributes;

    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    /**
     * The commercial terms of an offer. Editable while the offer is a Draft or Initiated; from
     * release onward they change only through an offer revision (Phase 8.3).
     *
     * @var array<int, string>
     */
    public const array TERMS = ['designation_id', 'location_id', 'offered_ctc', 'fixed_salary', 'variable_salary', 'joining_bonus', 'offer_date', 'offer_expiry', 'expected_joining_date', 'offer_letter_template_id', 'offer_letter_body'];

    /**
     * Compensation figures — recorded as changed in the audit log, never copied into it.
     *
     * @var array<int, string>
     */
    public const array COMPENSATION = ['offered_ctc', 'fixed_salary', 'variable_salary', 'joining_bonus'];

    /**
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        $base = ['status', 'accepted_at', 'candidate_application_id', 'offer_code'];

        return $this->termsAreEditable() ? $base : [...$base, ...self::TERMS];
    }

    public function lifecycleOwner(): string
    {
        return 'OfferService';
    }

    /**
     * Whether the offer's terms may still be edited directly (before release).
     */
    public function termsAreEditable(): bool
    {
        $status = $this->getOriginal('status') ?? $this->status;

        return in_array($status instanceof OfferStatus ? $status : OfferStatus::tryFrom((string) $status), [OfferStatus::Draft, OfferStatus::Initiated, null], true);
    }

    /**
     * @return array<int, string>
     */
    public function auditRedactedAttributes(): array
    {
        return [...self::COMPENSATION, 'offer_letter_body'];
    }

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
     * Every set of terms released or proposed for this offer (Phase 8.3).
     *
     * @return HasMany<OfferRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(OfferRevision::class)->orderBy('revision');
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
     * The template this offer's letter was started from; see OfferLetterRenderer for how the letter
     * body is resolved.
     *
     * @return BelongsTo<OfferLetterTemplate, $this>
     */
    public function offerLetterTemplate(): BelongsTo
    {
        return $this->belongsTo(OfferLetterTemplate::class);
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
