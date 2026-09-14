<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An immutable log of every status transition an offer goes through — see OfferService, the only
 * writer of this table. Offers are financially sensitive (Section 27/28), so this trail is never
 * overwritten or deleted: updating/deleting a row through Eloquent throws.
 */
#[Fillable(['offer_id', 'from_status', 'to_status', 'changed_by', 'remarks'])]
class OfferStatusHistory extends Model
{
    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Offer status history is append-only and cannot be modified.');
        });

        static::deleting(function (): never {
            throw new LogicException('Offer status history is append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => OfferStatus::class,
            'to_status' => OfferStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
