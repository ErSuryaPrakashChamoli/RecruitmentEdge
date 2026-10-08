<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Widgets\DistributionStats;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJobPostings extends ListRecords
{
    protected static string $resource = JobPostingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('careerSite')->label('Open career site')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')->url(route('careers.index'), shouldOpenInNewTab: true),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [DistributionStats::class];
    }
}
