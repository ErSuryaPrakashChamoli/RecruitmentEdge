<?php

use App\Enums\InvoiceStatus;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Services\Billing\InvoiceNumberer;
use App\Services\Billing\InvoiceService;
use Carbon\Carbon;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: invoices are issued by the platform from one gap-free series, from the subscription's
 * price snapshot, once per period, and are immutable once issued.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
});

test('invoice numbers form one platform series across tenants, per financial year, without gaps', function (): void {
    $this->world->subscribe('paying');
    $this->world->subscribe('other');
    $numbers = [$this->world->invoices('paying')->sole()->number, $this->world->invoices('other')->sole()->number];
    $series = app(InvoiceNumberer::class)->series(now());

    expect($numbers)->toBe(["{$series}/000001", "{$series}/000002"])
        ->and(app(InvoiceNumberer::class)->series(Carbon::create(2027, 3, 31)))->toBe('RE/2026-27')
        ->and(app(InvoiceNumberer::class)->series(Carbon::create(2027, 4, 1)))->toBe('RE/2027-28');

    config(['billing.invoice_number.year_starts_month' => 1, 'billing.invoice_number.prefix' => 'MX']);

    expect(app(InvoiceNumberer::class)->series(Carbon::create(2027, 3, 31)))->toBe('MX/2027');
});

test('a number given back by a rolled-back issue is reused: no gaps', function (): void {
    $numberer = app(InvoiceNumberer::class);

    try {
        DB::transaction(function () use ($numberer): void {
            $numberer->next();

            throw new RuntimeException('issue failed');
        });
    } catch (RuntimeException) {
    }

    expect($numberer->next())->toEndWith('/000001');
});

test('an issued invoice is immutable apart from its payment progress, and never deleted', function (): void {
    $this->world->subscribe('paying');
    $invoice = $this->world->invoices('paying')->sole();

    $this->world->in('paying', function () use ($invoice): void {
        expect(fn () => $invoice->fresh()->update(['total_minor' => 1]))->toThrow(LogicException::class, 'immutable')
            ->and(fn () => $invoice->fresh()->update(['number' => 'X']))->toThrow(LogicException::class)
            ->and(fn () => $invoice->fresh()->update(['period_start' => now()->addYear()]))->toThrow(LogicException::class)
            ->and(fn () => $invoice->fresh()->delete())->toThrow(LogicException::class);
    });
});

test('one invoice per period: issuing a period again returns it', function (): void {
    $subscription = $this->world->subscribe('paying');
    $invoice = $this->world->invoices('paying')->sole();

    $again = $this->world->in('paying', fn () => DB::transaction(fn () => app(InvoiceService::class)->issue($subscription->fresh(), $subscription->current_period_start, $subscription->current_period_end)));

    expect($again->id)->toBe($invoice->id)
        ->and($this->world->in('paying', fn () => BillingInvoice::query()->count()))->toBe(1);
});

test('only a due invoice nobody paid towards can be voided — by the platform, with a reason', function (): void {
    $this->world->subscribe('paying');
    $invoice = $this->world->invoices('paying')->sole();

    expect(fn () => app(InvoiceService::class)->void($invoice, 'Issued in error'))->toThrow(DomainException::class, 'no payment');

    $this->world->decline('paying');
    $voided = app(InvoiceService::class)->void($invoice->fresh(), 'Issued in error');

    expect($voided->status)->toBe(InvoiceStatus::Void)
        ->and($voided->amount_due_minor)->toBe(0)
        ->and($voided->number)->toBe($invoice->number)
        ->and(fn () => app(InvoiceService::class)->void($invoice->fresh(), ' '))->toThrow(DomainException::class, 'reason')
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'invoice_voided')->count()))->toBe(1);
});

test('the invoice carries the price snapshot, the period and a tax structure without inventing tax', function (): void {
    $this->world->subscribe('paying');
    $invoice = $this->world->invoices('paying')->sole();

    expect($invoice->subtotal_minor)->toBe(499900)
        ->and($invoice->tax_minor)->toBe(0)
        ->and($invoice->tax_details)->toBeNull()
        ->and($invoice->total_minor)->toBe(499900)
        ->and($invoice->amount_due_minor)->toBe(499900)
        ->and($invoice->currency)->toBe('INR')
        ->and($invoice->billing_price_id)->toBe($this->world->prices['growth']->id)
        ->and($invoice->description)->toContain('Growth v1');
});
