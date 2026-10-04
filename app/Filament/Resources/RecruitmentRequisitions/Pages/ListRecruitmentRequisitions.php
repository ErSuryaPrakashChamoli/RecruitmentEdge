<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Enums\Entitlement;
use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Services\Entitlements\EntitlementService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentRequisitions extends ListRecords
{
    use HasSavedTableViews;

    protected static string $resource = RecruitmentRequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedTableViewActions(),
            // SaaS-3: unavailable (with the reason) when the tenant's plan allows no more active
            // requisitions; the service refuses it anyway.
            CreateAction::make()
                ->disabled(fn (): bool => ! app(EntitlementService::class)->canAdd(Entitlement::RequisitionsActiveMax))
                ->tooltip(fn (): ?string => app(EntitlementService::class)->canAdd(Entitlement::RequisitionsActiveMax) ? null : Entitlement::RequisitionsActiveMax->unavailableMessage()),
        ];
    }
}
