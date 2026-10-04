<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use App\Models\BillingPayment as Payment;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\Money;
use App\Services\Billing\PaymentService;
use App\Services\Billing\Providers\ProviderUnavailable;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * SaaS-4: platform staff record an offline payment against an invoice (manual and contract
 * billing), refund a payment, or void an unpaid invoice — explicit, with a reason, audited.
 */
#[Signature('billing:payment
    {action : record, refund or void}
    {target : An invoice number (record, void) or a payment reference (refund)}
    {amount? : Decimal amount (record, refund)}
    {--reference= : The external reference of an offline payment (record)}
    {--reason= : Why (required)}')]
#[Description('Record an offline payment, refund a payment or void an invoice (platform)')]
class BillingPayment extends Command
{
    public function handle(PaymentService $payments, InvoiceService $invoices): int
    {
        $reason = (string) $this->option('reason');

        try {
            $result = match ((string) $this->argument('action')) {
                'record' => (fn (BillingInvoice $invoice): string => $payments->recordManualPayment($invoice, Money::parse((string) $this->argument('amount'), $invoice->currency), (string) $this->option('reference'), $reason)->status->label())($this->invoice()),
                'refund' => (fn (Payment $payment): string => $payments->refund($payment, Money::parse((string) $this->argument('amount'), $payment->currency), $reason)->status->label())($this->payment()),
                'void' => $invoices->void($this->invoice(), $reason)->status->label(),
                default => throw new DomainException('Unknown action.'),
            };
        } catch (DomainException|InvalidArgumentException|ProviderUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done: {$result}.");

        return self::SUCCESS;
    }

    /**
     * Platform lookup by the invoice's globally unique number.
     */
    private function invoice(): BillingInvoice
    {
        return BillingInvoice::query()->withoutTenancy()->where('number', (string) $this->argument('target'))->first() ?? throw new DomainException('No such invoice.');
    }

    private function payment(): Payment
    {
        return Payment::query()->withoutTenancy()->where('reference', (string) $this->argument('target'))->first() ?? throw new DomainException('No such payment.');
    }
}
