<?php

namespace App\Filament\Resources\TalentPools\Pages;

use App\Filament\Resources\TalentPools\TalentPoolResource;
use App\Services\TalentPoolService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTalentPool extends CreateRecord
{
    protected static string $resource = TalentPoolResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(TalentPoolService::class)->create($data, auth()->user()?->employee);
    }
}
