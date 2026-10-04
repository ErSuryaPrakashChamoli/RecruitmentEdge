<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Pages\AiCopilot;
use App\Filament\Resources\RecruitmentRequisitions\Actions\RequisitionLifecycleActions;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\RecruitmentRequisition;
use App\Services\RequisitionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditRecruitmentRequisition extends EditRecord
{
    use GuardsDomainExceptions;

    protected static string $resource = RecruitmentRequisitionResource::class;

    public function getSubheading(): ?string
    {
        /** @var RecruitmentRequisition $requisition */
        $requisition = $this->getRecord();

        return "Status: {$requisition->status->label()} · Positions filled: {$requisition->filledOpeningsCount()} of {$requisition->openings} requested";
    }

    protected function getHeaderActions(): array
    {
        return [
            ...RequisitionLifecycleActions::make(),
            Action::make('askAi')
                ->label('Ask AI about this requisition')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => AiCopilot::canAccess())
                ->url(fn () => AiCopilot::linkForContext('requisition', $this->record->id)),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            // SaaS-3: restoring an active requisition takes room under the plan's limit.
            RestoreAction::make()
                ->using(fn (RecruitmentRequisition $record): bool => (bool) self::guarded('Requisition not restored', fn () => app(RequisitionService::class)->restore($record, Filament::auth()->user()))),
        ];
    }
}
