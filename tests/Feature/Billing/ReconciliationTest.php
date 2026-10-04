<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Services\Billing\BillingReconciler;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: reconciliation reports match / mismatch / unresolved and changes nothing on its own; with
 * --apply it applies the provider's word through the same locked, forward-only path as a webhook.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
    $this->world->subscribe('paying');
});

function reconcile(BillingWorld $world, bool $apply = false): array
{
    return $world->in('paying', fn (): array => app(BillingReconciler::class)->reconcile($apply));
}

test('consistent billing reconciles as a match, and the run is audited', function (): void {
    $this->world->pay('paying');

    $report = reconcile($this->world);

    expect($report['mismatch'])->toBe(0)
        ->and($report['unresolved'])->toBe(0)
        ->and($report['match'])->toBe(2)
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'billing_reconciled')->count()))->toBe(1);
});

test('a lost webhook shows as a mismatch, changes nothing by itself, and --apply settles it once', function (): void {
    $this->world->provider()->settle((string) $this->world->lastPayment('paying')->provider_payment_ref, PaymentStatus::Succeeded);

    $report = reconcile($this->world);

    expect($report['mismatch'])->toBe(1)
        ->and($report['findings'][0])->toContain('local pending, provider succeeded')
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Pending);

    expect(reconcile($this->world, apply: true)['applied'])->toBe(1)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active)
        ->and(reconcile($this->world, apply: true)['mismatch'])->toBe(0);
});

test('while the provider cannot be asked, items are unresolved and local state stands', function (): void {
    $this->world->provider()->outage = true;

    $report = reconcile($this->world, apply: true);

    expect($report['unresolved'])->toBe(1)
        ->and($report['applied'])->toBe(0)
        ->and($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::Pending);
});

test('an invoice that disagrees with its own payments is reported, never corrected silently', function (): void {
    $this->world->pay('paying');
    $this->world->in('paying', fn () => DB::table('billing_invoices')->where('tenant_id', $this->world->tenants['paying']->id)->update(['amount_paid_minor' => 1]));

    $report = reconcile($this->world);

    expect($report['mismatch'])->toBe(1)
        ->and($report['findings'][0])->toContain('settled payments 499900')
        ->and($this->world->in('paying', fn () => BillingInvoice::query()->sole()->amount_paid_minor))->toBe(1);
});

test('the console reconciles every open tenant and fails when something needs attention', function (): void {
    $this->artisan('billing:reconcile')->assertSuccessful();

    $this->world->provider()->settle((string) $this->world->lastPayment('paying')->provider_payment_ref, PaymentStatus::Succeeded);

    $this->artisan('billing:reconcile', ['slug' => 'paying-co'])->expectsOutputToContain('1 mismatch')->assertFailed();
    $this->artisan('billing:reconcile', ['slug' => 'paying-co', '--apply' => true])->expectsOutputToContain('1 applied')->assertFailed();
    $this->artisan('billing:reconcile', ['slug' => 'paying-co'])->assertSuccessful();
});
