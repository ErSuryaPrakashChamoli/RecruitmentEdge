<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SaaS-4: one attempt to pay one invoice — through the provider (reference = our idempotency key
 * and the opaque id the provider echoes back; provider_payment_ref = the provider's own id, set
 * once) or recorded manually by the platform. Its amount and currency never change; its status
 * only moves forward (PaymentStatus::rank, PaymentService). Never deleted. No card data, ever: the
 * provider holds payment credentials.
 */
#[Fillable(['billing_invoice_id', 'reference', 'method', 'provider', 'provider_payment_ref', 'status', 'currency', 'amount_minor', 'amount_refunded_minor', 'attempted_at', 'completed_at', 'failure_code', 'failure_message', 'external_reference', 'recorded_by'])]
class BillingPayment extends Model
{
    use BelongsToTenant;

    private const FIXED = ['billing_invoice_id', 'reference', 'method', 'provider', 'currency', 'amount_minor'];

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            if (array_intersect(array_keys($payment->getDirty()), self::FIXED) !== []) {
                throw new LogicException('A payment\'s amount, currency, invoice and reference never change.');
            }

            if ($payment->isDirty('provider_payment_ref') && $payment->getOriginal('provider_payment_ref') !== null) {
                throw new LogicException('A payment\'s provider reference is recorded once.');
            }
        });

        static::deleting(fn () => throw new LogicException('A payment is never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            'attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount_minor, $this->currency);
    }
}
