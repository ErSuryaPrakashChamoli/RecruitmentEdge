<?php

namespace App\Models;

use App\Enums\OfferRevisionStatus;
use Database\Factories\OfferRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase 8.3: one set of offer terms — the original release or a later revision. Written only by
 * OfferService. The terms never change once written; only the status moves on (a pending revision
 * is released or cancelled, a released one is superseded).
 */
#[Fillable([
    'offer_id', 'revision', 'status', 'designation_id', 'location_id', 'offered_ctc', 'fixed_salary', 'variable_salary',
    'joining_bonus', 'offer_expiry', 'expected_joining_date', 'offer_letter_body', 'reason', 'requested_by', 'decided_by', 'decided_at',
])]
class OfferRevision extends Model
{
    /** @use HasFactory<OfferRevisionFactory> */
    use HasFactory;

    /**
     * The offer terms a revision carries (copied onto the offer when it is released).
     *
     * @var array<int, string>
     */
    public const array TERMS = ['designation_id', 'location_id', 'offered_ctc', 'fixed_salary', 'variable_salary', 'joining_bonus', 'offer_expiry', 'expected_joining_date', 'offer_letter_body'];

    /**
     * @var array<int, string>
     */
    private const array MUTABLE = ['status', 'decided_by', 'decided_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'status' => OfferRevisionStatus::class,
            'offered_ctc' => 'decimal:2',
            'fixed_salary' => 'decimal:2',
            'variable_salary' => 'decimal:2',
            'joining_bonus' => 'decimal:2',
            'offer_expiry' => 'date',
            'expected_joining_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if (array_diff(array_keys($revision->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Offer revision terms are immutable — request a new revision.');
            }
        });
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
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
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
