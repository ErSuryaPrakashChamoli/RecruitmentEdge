<?php

namespace App\Filament\Pages;

use App\Enums\EntitlementType;
use App\Models\TenantPlanAssignment;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantContext;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * SaaS-3: this organisation's own plan, trial and usage — read-only, for its administrators.
 * Everything shown is this tenant's (TenantScope); the plan is changed by the platform, never here.
 */
class PlanAndUsage extends Page
{
    protected string $view = 'filament.pages.plan-and-usage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Plan & Usage';

    protected static ?string $title = 'Plan & usage';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && ($user->can('users.manage') || $user->can('settings.manage'));
    }

    /**
     * @return array{plan: string|null, since: string|null, status: string, trial_ends_at: string|null, trial_days_left: int|null, lines: list<array{label: string, kind: string, granted: string, usage: int|null, over: bool, near: bool}>}
     */
    public function summary(): array
    {
        $tenant = TenantContext::current()->requireTenant();
        $assignment = TenantPlanAssignment::query()->where('is_current', true)->with('planVersion.plan')->first();
        $lines = [];

        foreach (app(EntitlementService::class)->overview() as $line) {
            $effective = $line['effective'];
            $isLimit = $line['entitlement']->type() === EntitlementType::Limit;
            $granted = match (true) {
                ! $effective->enabled => 'Not included',
                ! $isLimit => 'Included',
                $effective->unlimited => 'Unlimited',
                default => (string) $effective->limit,
            };
            $over = $isLimit && $effective->enabled && ! $effective->unlimited && $line['usage'] > (int) $effective->limit;
            $near = $isLimit && $effective->enabled && ! $effective->unlimited && $line['usage'] >= (int) $effective->limit;

            $lines[] = ['label' => $line['entitlement']->label(), 'kind' => $isLimit ? 'limit' : 'feature', 'granted' => $granted, 'usage' => $line['usage'], 'over' => $over, 'near' => $near];
        }

        return [
            'plan' => $assignment?->planVersion?->plan?->name,
            'since' => $assignment?->effective_from?->toFormattedDateString(),
            'status' => $tenant->effectiveStatus()->label(),
            'trial_ends_at' => $tenant->trial_ends_at?->toFormattedDateString(),
            'trial_days_left' => $tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture() ? (int) ceil(now()->diffInHours($tenant->trial_ends_at) / 24) : null,
            'lines' => $lines,
        ];
    }
}
