<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Billing\BillingReconciler;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-4: compares each tenant's billing with itself and with the provider (match / mismatch /
 * unresolved). Report only, unless --apply: then a provider's statement is applied through the
 * same locked path as a webhook. Scheduled daily without --apply (platform task).
 */
#[Signature('billing:reconcile {slug? : One tenant} {--apply : Apply the provider\'s state where it differs}')]
#[Description('Reconcile billing with the payment provider (platform)')]
class BillingReconcile extends Command
{
    public function handle(BillingReconciler $reconciler): int
    {
        $tenants = Tenant::query()
            ->when($this->argument('slug'), fn ($query, $slug) => $query->where('slug', $slug), fn ($query) => $query->whereNotIn('status', [TenantStatus::Provisioning->value, TenantStatus::Deleted->value]))
            ->orderBy('id')->get();
        $attention = false;

        foreach ($tenants as $tenant) {
            $report = TenantContext::current()->run($tenant, fn (): array => $reconciler->reconcile((bool) $this->option('apply')));
            $attention = $attention || $report['mismatch'] > 0 || $report['unresolved'] > 0;

            $this->line("{$tenant->slug}: {$report['match']} match, {$report['mismatch']} mismatch, {$report['unresolved']} unresolved".($this->option('apply') ? ", {$report['applied']} applied" : ''));

            foreach ($report['findings'] as $finding) {
                $this->line("  - {$finding}");
            }
        }

        return $attention ? self::FAILURE : self::SUCCESS;
    }
}
