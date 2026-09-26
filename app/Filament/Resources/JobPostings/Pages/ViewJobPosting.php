<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Filament\Resources\JobPostings\Actions\PublishingActions;
use App\Filament\Resources\JobPostings\JobPostingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewJobPosting extends ViewRecord
{
    protected static string $resource = JobPostingResource::class;

    protected function getHeaderActions(): array
    {
        return [...PublishingActions::all(), EditAction::make()];
    }
}
