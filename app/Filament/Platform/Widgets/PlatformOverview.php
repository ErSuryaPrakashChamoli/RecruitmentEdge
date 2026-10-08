<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\ComplianceExportStatus;
use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Enums\TenantStatus;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\ComplianceExport;
use App\Models\PlatformEvent;
use App\Models\Tenant;
use App\Models\TenantDeletionRequest;
use App\Services\Platform\PlatformDirectory;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-5: counts only — tenants by state, open platform work, failures waiting for an operator.
 */
class PlatformOverview extends StatsOverviewWidget
{
    use InteractsWithPlatform;

    public static function canView(): bool
    {
        return self::allows(PlatformCapability::TenantsView);
    }

    protected function getStats(): array
    {
        $byStatus = Tenant::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $usable = collect(TenantStatus::usableValues())->sum(fn (string $status): int => (int) ($byStatus[$status] ?? 0));
        $closed = (int) $byStatus->sum() - $usable;
        $support = app(PlatformDirectory::class)->supportCounts();

        return [
            Stat::make('Tenants in use', $usable)->description("{$closed} closed, suspended or provisioning"),
            Stat::make('Open deletions', TenantDeletionRequest::query()->where('is_open', true)->count())
                ->description(TenantDeletionRequest::query()->where('status', DeletionRequestStatus::Failed->value)->count().' failed purges')
                ->color('danger'),
            Stat::make('Support access', $support['active'])->description("{$support['requested']} awaiting the tenant"),
            Stat::make('Critical events', PlatformEvent::query()->where('severity', PlatformEventSeverity::Critical->value)->whereNull('acknowledged_at')->count())
                ->description('Not acknowledged')
                ->color('danger'),
            Stat::make('Failed jobs', DB::table('failed_jobs')->count())->description('All tenants and the platform'),
            Stat::make('Compliance exports', ComplianceExport::query()->where('status', ComplianceExportStatus::Ready->value)->count())->description('Ready, until they expire'),
        ];
    }
}
