<?php

namespace App\Filament\Resources\RecruitmentRejectionReasons\Pages;

use App\Filament\Resources\RecruitmentRejectionReasons\RecruitmentRejectionReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecruitmentRejectionReasons extends ListRecords
{
    protected static string $resource = RecruitmentRejectionReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
