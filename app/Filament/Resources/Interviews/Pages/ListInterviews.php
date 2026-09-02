<?php

namespace App\Filament\Resources\Interviews\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Resources\Interviews\InterviewResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInterviews extends ListRecords
{
    use HasSavedTableViews;

    protected static string $resource = InterviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedTableViewActions(),
            CreateAction::make(),
        ];
    }
}
