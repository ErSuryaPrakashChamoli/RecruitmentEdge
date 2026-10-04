<?php

namespace App\Console\Commands;

use App\Enums\BillingEventStatus;
use App\Enums\TenantStatus;
use App\Models\BillingEvent;
use App\Models\Tenant;
use App\Services\Billing\BillingClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-4 (platform task, hourly): moves billing on in every open tenant — renewals, trial-end
 * invoices, grace and cancellation ends, collection retries (BillingClock) — one tenant at a time,
 * each inside its own tenant; a failing tenant never stops the others. Also reports provider events
 * still unprocessed after an hour.
 */
#[Signature('billing:sweep')]
#[Description('Advance subscriptions in every tenant and report stale provider events (platform)')]
class BillingSweep extends Command
{
    public function handle(): int
    {
        $failed = 0;

        Tenant::query()->whereNotIn('status', [TenantStatus::Provisioning->value, TenantStatus::Cancelled->value, TenantStatus::DeletionPending->value, TenantStatus::Deleted->value])->orderBy('id')->each(function (Tenant $tenant) use (&$failed): void {
            try {
                $result = TenantContext::current()->run($tenant, fn (): array => app(BillingClock::class)->tick());

                if ($result['changed'] !== [] || $result['collected'] > 0) {
                    $this->line("{$tenant->slug}: ".implode(', ', $result['changed']).($result['collected'] > 0 ? " ({$result['collected']} collection(s))" : ''));
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('billing.sweep_failed', ['tenant_id' => $tenant->id, 'exception' => $e::class]);
            }
        });

        $stale = BillingEvent::query()->where('status', BillingEventStatus::Received->value)->where('received_at', '<=', now()->subHour())->count();

        if ($stale > 0) {
            Log::warning('platform.alert', ['alert' => 'billing_events_unprocessed', 'count' => $stale]);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
