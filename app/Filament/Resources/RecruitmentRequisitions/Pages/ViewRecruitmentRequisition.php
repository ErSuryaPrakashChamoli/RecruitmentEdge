<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRecruitmentRequisition extends ViewRecord
{
    protected static string $resource = RecruitmentRequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('intelligence')
                ->label('EDGE Intelligence')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('intelligence.view') ?? false)
                ->url(fn () => RecruitmentRequisitionResource::getUrl('intelligence', ['record' => $this->getRecord()])),
            EditAction::make(),
        ];
    }
}
