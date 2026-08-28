<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentRequisitions extends ListRecords
{
    protected static string $resource = RecruitmentRequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
