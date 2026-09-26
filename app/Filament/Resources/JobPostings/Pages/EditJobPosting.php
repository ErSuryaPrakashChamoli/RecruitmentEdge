<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Models\JobPosting;
use App\Services\Distribution\JobDistributionService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditJobPosting extends EditRecord
{
    protected static string $resource = JobPostingResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var JobPosting $record */
        return app(JobDistributionService::class)->savePosting($record->requisition, $data, auth()->user()?->employee);
    }
}
