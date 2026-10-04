<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * SaaS-4: what the tenant commercially purchased — a SaaS-3 plan version, at a price snapshot
 * (amount, currency, interval) taken when it was bought or changed; billing never re-reads today's
 * catalog for it. Its status moves only through SubscriptionStateMachine. At most one live
 * (non-terminal) subscription per tenant: is_live true, unique per tenant; ended ones keep null.
 */
#[Fillable(['billing_customer_id', 'plan_version_id', 'billing_price_id', 'source', 'provider', 'provider_subscription_ref', 'status', 'is_live', 'currency', 'interval', 'amount_minor', 'activated_at', 'current_period_start', 'current_period_end', 'trial_start', 'trial_end', 'cancel_at', 'cancelled_at', 'ended_at', 'past_due_since', 'grace_ends_at', 'contract_reference', 'created_by'])]
class BillingSubscription extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A subscription is never deleted: it is billing history.'));
    }

    protected function casts(): array
    {
        return [
            'source' => SubscriptionSource::class,
            'status' => SubscriptionStatus::class,
            'interval' => BillingInterval::class,
            'is_live' => 'boolean',
            'amount_minor' => 'integer',
            'activated_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_start' => 'datetime',
            'trial_end' => 'datetime',
            'cancel_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'past_due_since' => 'datetime',
            'grace_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    /**
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /**
     * @return BelongsTo<BillingPrice, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(BillingPrice::class, 'billing_price_id');
    }

    /**
     * @return HasMany<BillingInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function money(): Money
    {
        return Money::of((int) $this->amount_minor, $this->currency);
    }
}
