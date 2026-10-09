<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\PlatformCapability;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Services\Platform\PlatformDirectory;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Platform commercial UI: what needs a commercial decision now — tenants, the plans in use,
 * subscriptions and invoices needing attention, failed payments, lifecycle problems. Counts and
 * stored amounts only (each in its own currency); no forecasting, no converted totals.
 */
class CommercialOverview extends StatsOverviewWidget
{
    use InteractsWithPlatform;

    protected ?string $heading = 'Commercial';

    public static function canView(): bool
    {
        return self::allows(PlatformCapability::CommercialManage);
    }

    protected function getStats(): array
    {
        $overview = app(PlatformDirectory::class)->commercialOverview();
        $status = fn (TenantStatus $status): int => $overview['by_status'][$status->value] ?? 0;
        $subscriptions = fn (SubscriptionStatus $status): int => $overview['subscriptions'][$status->value] ?? 0;
        $attention = $subscriptions(SubscriptionStatus::PastDue) + $subscriptions(SubscriptionStatus::Unpaid);
        $problems = $status(TenantStatus::Suspended) + $overview['provisioning_errors'];

        return [
            Stat::make('Tenants', $overview['tenants'])
                ->description("{$status(TenantStatus::Active)} active · {$status(TenantStatus::Trial)} on trial · {$status(TenantStatus::PastDue)} past due"),
            Stat::make('Plans in use', count($overview['plans']))
                ->description($overview['plans'] === [] ? 'No tenant has a plan' : collect($overview['plans'])->map(fn (int $tenants, string $plan): string => "{$plan} {$tenants}")->implode(' · ')),
            Stat::make('Subscriptions needing attention', $attention)
                ->description("{$subscriptions(SubscriptionStatus::PastDue)} past due · {$subscriptions(SubscriptionStatus::Unpaid)} unpaid · {$subscriptions(SubscriptionStatus::Cancelling)} ending at period end")
                ->color($attention > 0 ? 'danger' : 'success'),
            Stat::make('Open invoices', $overview['open_invoices'])
                ->description('Outstanding: '.($overview['outstanding'] === [] ? 'none' : implode('; ', $overview['outstanding'])))
                ->color($overview['open_invoices'] > 0 ? 'warning' : 'success'),
            Stat::make('Failed payments', $overview['failed_payments'])
                ->description('Last 30 days')
                ->color($overview['failed_payments'] > 0 ? 'warning' : 'success'),
            Stat::make('Lifecycle problems', $problems)
                ->description("{$status(TenantStatus::Suspended)} suspended · {$overview['provisioning_errors']} failed provisioning")
                ->color($problems > 0 ? 'warning' : 'success'),
        ];
    }
}
