<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SaaS-4: what a SaaS-3 plan version costs, in one currency, per interval. Platform catalog. A
 * price is never edited — a new price replaces it (the old one is retired, kept for the
 * subscriptions and invoices that used it). One current price per plan version, currency and
 * interval (unique, is_current null for history). Written only by PriceCatalogService.
 */
#[Fillable(['plan_version_id', 'currency', 'interval', 'amount_minor', 'status', 'is_current', 'effective_from', 'effective_until', 'provider', 'provider_price_ref', 'created_by'])]
class BillingPrice extends Model
{
    private const MUTABLE = ['status', 'is_current', 'effective_until', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $price): void {
            $changed = array_diff(array_keys($price->getDirty()), self::MUTABLE);

            // A provider price reference may be recorded once, never changed.
            if ($changed === ['provider_price_ref'] && $price->getOriginal('provider_price_ref') === null) {
                return;
            }

            if ($changed !== []) {
                throw new LogicException('A price is never edited: publish a new price instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('A price is never deleted: subscriptions and invoices refer to it.'));
    }

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'amount_minor' => 'integer',
            'is_current' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function money(): Money
    {
        return Money::of((int) $this->amount_minor, $this->currency);
    }
}
