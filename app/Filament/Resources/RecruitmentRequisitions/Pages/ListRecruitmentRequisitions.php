<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
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
            CreateAction::make(),
        ];
    }
}
