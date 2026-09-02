<?php

namespace App\Filament\Resources\CandidateJoinings\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Widgets\JoiningControlCenterWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCandidateJoinings extends ListRecords
{
    use HasSavedTableViews;

    protected static string $resource = CandidateJoiningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedTableViewActions(),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            JoiningControlCenterWidget::class,
        ];
    }
}
