<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\BillingPayment;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingCustomerService;
use App\Services\Billing\Money;
use App\Services\Billing\PaymentService;
use App\Services\Billing\Providers\ProviderUnavailable;
use App\Services\Billing\SubscriptionService;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: payments — partial, complete, excessive, refunded, recorded offline, attempted while the
 * provider is down. The invoice's amount is ours; a payment state only moves forward.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
});

test('offline payments for manual billing: explicit, partial then complete, each audited', function (): void {
    $this->world->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $invoice = $this->world->invoices('paying')->sole();

    expect($this->world->lastPayment('paying'))->toBeNull();

    app(PaymentService::class)->recordManualPayment($invoice, Money::parse('1000.00', 'INR'), 'NEFT UTR 123', 'Bank transfer received');

    expect($this->world->invoices('paying')->sole()->amount_due_minor)->toBe(399900)
        ->and($this->world->invoices('paying')->sole()->status)->toBe(InvoiceStatus::Open)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Pending);

    app(PaymentService::class)->recordManualPayment($invoice->fresh(), Money::parse('3999.00', 'INR'), 'NEFT UTR 124', 'Balance received');

    expect($this->world->invoices('paying')->sole()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active)
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'payment_recorded_manually')->count()))->toBe(2);
});

test('an offline payment must be real: a reason, a reference, the invoice currency, more than nothing — and never for provider billing', function (): void {
    $this->world->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $invoice = $this->world->invoices('paying')->sole();
    $payments = app(PaymentService::class);

    expect(fn () => $payments->recordManualPayment($invoice, Money::parse('10.00', 'INR'), '', 'x'))->toThrow(DomainException::class, 'external reference')
        ->and(fn () => $payments->recordManualPayment($invoice, Money::parse('10.00', 'USD'), 'r', 'x'))->toThrow(DomainException::class, 'never converted')
        ->and(fn () => $payments->recordManualPayment($invoice, Money::zero('INR'), 'r', 'x'))->toThrow(DomainException::class, 'more than nothing')
        ->and(fn () => $payments->recordManualPayment($invoice, Money::parse('10.00', 'INR'), 'r', 'x', $this->world->admins['paying']->fresh()))->toThrow(DomainException::class, 'platform administrator');

    $this->world->subscribe('other');

    expect(fn () => $payments->recordManualPayment($this->world->invoices('other')->sole(), Money::parse('4999.00', 'INR'), 'r', 'x'))->toThrow(DomainException::class, 'through the provider');
});

test('paying more than is due is recorded and flagged for the platform, never silently absorbed', function (): void {
    $this->world->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $invoice = $this->world->invoices('paying')->sole();
    app(PaymentService::class)->recordManualPayment($invoice, Money::parse('4000.00', 'INR'), 'r1', 'part');
    app(PaymentService::class)->recordManualPayment($invoice->fresh(), Money::parse('2000.00', 'INR'), 'r2', 'rest, overpaid');

    expect($this->world->invoices('paying')->sole()->amount_paid_minor)->toBe(600000)
        ->and($this->world->invoices('paying')->sole()->amount_due_minor)->toBe(0)
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'billing_overpayment')->count()))->toBe(1);
});

test('refunds: through the provider, partial then complete, never more than was paid — and the invoice and subscription stay as they were', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $payment = $this->world->lastPayment('paying');
    $payments = app(PaymentService::class);

    $payments->refund($payment, Money::parse('999.00', 'INR'), 'Goodwill credit');

    expect($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($this->world->lastPayment('paying')->amount_refunded_minor)->toBe(99900)
        ->and(fn () => $payments->refund($payment->fresh(), Money::parse('4000.01', 'INR'), 'Too much'))->toThrow(DomainException::class, 'Only up to INR 4,000.00')
        ->and(fn () => $payments->refund($payment->fresh(), Money::parse('1.00', 'INR'), 'x', $this->world->admins['paying']->fresh()))->toThrow(DomainException::class, 'platform administrator');

    $payments->refund($payment->fresh(), Money::parse('4000.00', 'INR'), 'Full refund');

    expect($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::Refunded)
        ->and($this->world->invoices('paying')->sole()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active);

    // The provider's own refund notification afterwards changes nothing more.
    $this->world->webhook('payment.refunded', $this->world->lastPayment('paying'), ['refunded' => '4999.00'])->assertOk();

    expect($this->world->lastPayment('paying')->amount_refunded_minor)->toBe(499900);
});

test('while the provider is down nothing is assumed: the attempt stays pending and is recognised later', function (): void {
    // Unreachable before anything exists: subscribing fails visibly and creates nothing.
    $this->world->provider()->outage = true;

    expect(fn () => $this->world->subscribe('paying'))->toThrow(ProviderUnavailable::class)
        ->and($this->world->subscription('paying'))->toBeNull();

    // Unreachable at collection: the subscription and invoice exist, the attempt stays pending.
    $this->world->provider()->outage = false;
    $this->world->in('paying', fn () => app(BillingCustomerService::class)->ensureProviderCustomer(app(BillingCustomerService::class)->forCurrentTenant(), $this->world->provider()));
    $this->world->provider()->outage = true;
    $this->world->subscribe('paying');
    $payment = $this->world->lastPayment('paying');

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_payment_ref)->toBeNull()
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Pending);

    // The provider comes back; the next collection reuses the same attempt (same idempotency key).
    $this->world->provider()->outage = false;
    $this->world->in('paying', fn () => app(SubscriptionService::class)->collectSafely($this->world->tenants['paying']->id, $this->world->invoices('paying')->sole()));

    expect($this->world->in('paying', fn () => BillingPayment::query()->count()))->toBe(1)
        ->and($this->world->lastPayment('paying')->provider_payment_ref)->toStartWith('fpay_');

    $this->world->pay('paying')->assertOk();

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active);
});

test('a provider\'s failure text never carries a card or account number', function (): void {
    expect(PaymentService::safeMessage('Card 4242 4242 4242 4242 declined'))->toBe('Card [redacted] declined')
        ->and(PaymentService::safeMessage('Account 123456789012 closed'))->toBe('Account [redacted] closed')
        ->and(PaymentService::safeMessage('Insufficient funds'))->toBe('Insufficient funds');
});

test('a payment that arrives after the subscription ended is recorded, never re-opens it', function (): void {
    $this->world->subscribe('paying');
    $payment = $this->world->lastPayment('paying');
    app(SubscriptionService::class)->cancelForPlatform($this->world->subscription('paying'), 'Customer left', immediately: true);

    $this->world->pay('paying')->assertOk();

    expect($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'billing_payment_after_end')->count()))->toBe(1)
        ->and($payment->id)->toBe($this->world->lastPayment('paying')->id);
});

test('collection is retried on the dunning schedule, not on every tick', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $this->travel(1)->months();
    $this->travel(1)->minutes();
    $this->world->in('paying', fn () => app(BillingClock::class)->tick());
    $this->world->decline('paying');

    expect($this->world->in('paying', fn () => app(BillingClock::class)->tick())['collected'])->toBe(0);

    $this->travel(25)->hours();

    expect($this->world->in('paying', fn () => app(BillingClock::class)->tick())['collected'])->toBe(1)
        ->and($this->world->in('paying', fn () => BillingPayment::query()->count()))->toBe(3);
});
