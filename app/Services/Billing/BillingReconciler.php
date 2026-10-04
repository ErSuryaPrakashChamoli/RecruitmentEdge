<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\User;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Billing\Providers\ProviderUnavailable;
use App\Services\Platform\Commercial\PlatformOperatorGate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-4: compares the current tenant's billing with itself and with the provider, and reports
 * each item as match, mismatch or unresolved (the provider could not be asked). It never overwrites
 * local data on its own: with $apply, a provider's statement is applied through the same path as a
 * webhook (PaymentService::applyOutcome, under the billing locks, forward only) — so reconciliation
 * and a concurrent webhook converge on one result. Audited; platform only.
 */
class BillingReconciler
{
    private const RECENT_DAYS = 35;

    public function __construct(
        private readonly BillingProviderManager $providers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @return array{match: int, mismatch: int, unresolved: int, applied: int, findings: list<string>}
     */
    public function reconcile(bool $apply = false, ?User $operator = null): array
    {
        PlatformOperatorGate::assert($operator);
        $report = ['match' => 0, 'mismatch' => 0, 'unresolved' => 0, 'applied' => 0, 'findings' => []];

        $this->checkInvoices($report);
        $this->checkProvider($report, $apply);

        $customer = BillingCustomer::query()->first();

        if ($customer !== null) {
            AuditLog::record($customer, 'billing_reconciled', null, [...array_diff_key($report, ['findings' => true]), 'apply' => $apply, 'by_user_id' => $operator?->getKey()]);
        }

        if ($report['mismatch'] > 0 || $report['unresolved'] > 0) {
            Log::warning('billing.reconciliation_attention', ['tenant_id' => TenantContext::current()->id(), 'mismatch' => $report['mismatch'], 'unresolved' => $report['unresolved']]);
        }

        return $report;
    }

    /**
     * Each issued invoice agrees with its own payments.
     *
     * @param  array{match: int, mismatch: int, unresolved: int, applied: int, findings: list<string>}  $report
     */
    private function checkInvoices(array &$report): void
    {
        $settled = [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];

        BillingInvoice::query()->where('status', '!=', InvoiceStatus::Draft->value)->withSum(['payments as settled_minor' => fn ($payments) => $payments->whereIn('status', $settled)], 'amount_minor')->orderBy('id')->each(function (BillingInvoice $invoice) use (&$report): void {
            $settled = (int) $invoice->getAttribute('settled_minor');
            $consistent = $settled === $invoice->amount_paid_minor
                && ($invoice->status === InvoiceStatus::Void || $invoice->amount_due_minor === max(0, $invoice->total_minor - $invoice->amount_paid_minor))
                && ($invoice->status !== InvoiceStatus::Paid || $invoice->amount_due_minor === 0)
                && ($invoice->status !== InvoiceStatus::Open || $invoice->amount_due_minor > 0);

            $consistent ? $report['match']++ : $report['mismatch']++;

            if (! $consistent) {
                $report['findings'][] = "Invoice {$invoice->number}: paid {$invoice->amount_paid_minor}, settled payments {$settled}, due {$invoice->amount_due_minor}, status {$invoice->status->value}";
            }
        });
    }

    /**
     * Provider payments still pending, or settled recently, agree with what the provider says.
     *
     * @param  array{match: int, mismatch: int, unresolved: int, applied: int, findings: list<string>}  $report
     */
    private function checkProvider(array &$report, bool $apply): void
    {
        $payments = BillingPayment::query()->where('method', 'provider')
            ->where(fn ($query) => $query->where('status', PaymentStatus::Pending->value)->orWhere('updated_at', '>=', now()->subDays(self::RECENT_DAYS)))
            ->orderBy('id')->get();

        foreach ($payments as $payment) {
            $provider = $this->providers->find((string) $payment->provider);

            try {
                $state = $provider?->fetchPayment($payment->provider_payment_ref, $payment->reference);
            } catch (ProviderUnavailable) {
                $state = false;
            }

            if ($state === false || $provider === null) {
                $report['unresolved']++;
                $report['findings'][] = "Payment {$payment->reference}: the provider could not be asked";

                continue;
            }

            if ($state === null) {
                $report[$payment->status === PaymentStatus::Pending && $payment->provider_payment_ref === null ? 'unresolved' : 'mismatch']++;
                $report['findings'][] = "Payment {$payment->reference}: unknown to the provider";

                continue;
            }

            $agrees = $state->status === $payment->status && ($state->refunded === null || $state->refunded->minor === $payment->amount_refunded_minor);

            if ($agrees) {
                $report['match']++;

                continue;
            }

            $report['mismatch']++;
            $report['findings'][] = "Payment {$payment->reference}: local {$payment->status->value}, provider {$state->status->value}";

            if ($apply) {
                $result = DB::transaction(function () use ($payment, $state): string {
                    BillingLock::tenant((int) $payment->tenant_id);
                    [$subscription, $invoice, $locked] = BillingLock::payment((int) $payment->id);

                    return $this->payments->applyOutcome($subscription, $invoice, $locked, $state, 'reconciliation');
                });

                $report['applied'] += $result === 'applied' ? 1 : 0;
            }
        }
    }
}
