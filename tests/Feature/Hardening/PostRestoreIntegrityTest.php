<?php

use App\Enums\SubscriptionSource;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ApiCredential;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Billing\Money;
use App\Services\Billing\PaymentService;
use App\Services\Operations\IntegrityVerifier;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Billing\BillingWorld;

/*
 * Production readiness: after a restore, ops:verify-integrity also checks the SaaS state the services
 * keep consistent — and stays read-only. Failures are states no service produces; warnings are for an
 * operator to look at and do not fail the check.
 */

function postRestoreReport(): array
{
    return TenantContext::current()->runWithoutTenant(fn (): array => app(IntegrityVerifier::class)->report());
}

function postRestoreDeletionRequest(Tenant $tenant, string $status, ?bool $open): void
{
    DB::table('tenant_deletion_requests')->insert(['tenant_id' => $tenant->id, 'status' => $status, 'is_open' => $open, 'reason' => 'Contract ended', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

test('a sound database passes: no SaaS failure, every encrypted value readable, the database named', function (): void {
    IntegrationConnection::factory()->create();

    $report = postRestoreReport();

    expect($report['ok'])->toBeTrue()
        ->and($report['saas']['failures'])->toBe([])
        ->and($report['encryption'])->toMatchArray(['unreadable' => 0])
        ->and($report['encryption']['values'])->toBeGreaterThan(0)
        ->and($report['database']['driver'])->toBe(DB::connection()->getDriverName());
});

test('a state no service produces fails the check and is named', function (Closure $break, string $check): void {
    $break->call($this);

    $report = postRestoreReport();

    expect($report['ok'])->toBeFalse()
        ->and($report['saas']['failures'])->toHaveKey($check);
    $this->artisan('ops:verify-integrity')->assertFailed()->expectsOutputToContain($check);
})->with([
    'a deleted tenant without its purge' => [fn () => Tenant::factory()->create(['status' => 'deleted']), 'platform.deleted_tenant_without_purge'],
    'a purge whose tenant is not deleted' => [fn () => postRestoreDeletionRequest($this->tenant, 'purged', null), 'platform.purged_request_for_tenant_not_deleted'],
    'a tenant pending deletion without an open request' => [fn () => Tenant::factory()->create(['status' => 'deletion_pending']), 'platform.deletion_pending_without_open_request'],
    'a cancelled request still marked open' => [fn () => postRestoreDeletionRequest(Tenant::factory()->create(['status' => 'cancelled']), 'cancelled', true), 'platform.closed_request_still_open'],
    'a paid invoice with an amount due' => [function (): void {
        $world = BillingWorld::build();
        $world->subscribe('paying', 'growth', SubscriptionSource::Manual);
        DB::table('billing_invoices')->where('id', $world->invoices('paying')->sole()->id)->update(['status' => 'paid']);
    }, 'billing.paid_invoice_with_amount_due'],
    'a refund larger than its payment' => [function (): void {
        $world = BillingWorld::build();
        $world->subscribe('paying', 'growth', SubscriptionSource::Manual);
        app(PaymentService::class)->recordManualPayment($world->invoices('paying')->sole(), Money::parse('1000.00', 'INR'), 'NEFT UTR 900', 'Bank transfer received');
        DB::table('billing_payments')->where('id', $world->lastPayment('paying')->id)->update(['amount_refunded_minor' => 100001]);
    }, 'billing.refund_exceeds_payment'],
]);

test('a value the configured keys cannot read — a restore with the wrong APP_KEY — fails the check', function (): void {
    $connection = IntegrationConnection::factory()->create();
    DB::table('integration_connections')->where('id', $connection->id)->update(['secrets' => (new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))->encryptString('{}')]);

    expect(postRestoreReport())->ok->toBeFalse()->encryption->toMatchArray(['unreadable' => 1]);
    $this->artisan('ops:verify-integrity')->assertFailed()->expectsOutputToContain('1 unreadable');
});

test('what needs an operator\'s look is listed without failing the check', function (Closure $setUp, string $check): void {
    $setUp->call($this);

    $report = postRestoreReport();

    expect($report['ok'])->toBeTrue()
        ->and($report['saas']['warnings'])->toHaveKey($check);
    $this->artisan('ops:verify-integrity')->assertSuccessful()->expectsOutputToContain("(look at) {$check}");
})->with([
    'a usable tenant without a plan' => [fn () => Tenant::factory()->withoutPlan()->create(), 'commercial.usable_tenant_without_plan'],
    'a live credential whose owner left' => [function (): void {
        $credential = ApiCredential::factory()->create();
        DB::table('tenant_memberships')->where('tenant_id', $credential->tenant_id)->where('user_id', $credential->user_id)->update(['status' => 'revoked']);
    }, 'api.live_credential_of_non_member'],
    'a delivery left sending past its claim' => [function (): void {
        $event = WebhookEvent::query()->create(['event_key' => 'evt_'.Str::lower(Str::random(12)), 'type' => 'candidate.created', 'subject_type' => 'candidate', 'subject_id' => 1, 'payload' => [], 'occurred_at' => now()]);
        WebhookDelivery::query()->create(['webhook_event_id' => $event->id, 'integration_connection_id' => IntegrationConnection::factory()->create()->id, 'delivery_key' => 'dlv_'.Str::lower(Str::random(12)), 'status' => WebhookDeliveryStatus::Sending])
            ->forceFill(['claimed_until' => now()->subHour()])->save();
    }, 'integrations.delivery_sending_past_claim'],
]);

test('the check is read-only: it writes nothing, whatever it finds', function (): void {
    Tenant::factory()->create(['status' => 'deleted']);
    IntegrationConnection::factory()->create();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('ops:verify-integrity')->assertFailed();
    $this->artisan('ops:verify-integrity --json')->assertFailed();

    expect($writes)->toBe([]);
});
