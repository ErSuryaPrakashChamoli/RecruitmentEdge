<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * SaaS-4: an invoice for one billing period of one subscription, issued by the platform. Once
 * issued (numbered) it is immutable: only its payment progress (amount paid / due, paid, void) can
 * change, through InvoiceService and PaymentService. The amount comes from the subscription's price
 * snapshot when it was issued — never recomputed from today's price. Never deleted.
 */
#[Fillable(['billing_subscription_id', 'number', 'status', 'currency', 'subtotal_minor', 'discount_minor', 'tax_minor', 'tax_details', 'total_minor', 'amount_paid_minor', 'amount_due_minor', 'period_start', 'period_end', 'description', 'plan_version_id', 'billing_price_id', 'issued_at', 'due_at', 'paid_at', 'voided_at', 'void_reason'])]
class BillingInvoice extends Model
{
    use BelongsToTenant;

    /**
     * What may still change once the invoice is issued: its payment progress, nothing else.
     */
    private const PROGRESS = ['status', 'amount_paid_minor', 'amount_due_minor', 'paid_at', 'voided_at', 'void_reason', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $issued = $invoice->getOriginal('status') !== InvoiceStatus::Draft && $invoice->getOriginal('status') !== null;

            if ($issued && array_diff(array_keys($invoice->getDirty()), self::PROGRESS) !== []) {
                throw new LogicException('An issued invoice is immutable: correct it with a credit or a void, never an edit.');
            }
        });

        static::deleting(fn () => throw new LogicException('An invoice is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'tax_details' => 'array',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_due_minor' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'billing_subscription_id');
    }

    /**
     * @return HasMany<BillingPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function total(): Money
    {
        return Money::of((int) $this->total_minor, $this->currency);
    }

    public function amountDue(): Money
    {
        return Money::of((int) $this->amount_due_minor, $this->currency);
    }

    public function amountPaid(): Money
    {
        return Money::of((int) $this->amount_paid_minor, $this->currency);
    }
}
