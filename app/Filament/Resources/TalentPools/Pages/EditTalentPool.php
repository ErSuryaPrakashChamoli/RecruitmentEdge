<?php

namespace App\Filament\Resources\TalentPools\Pages;

use App\Filament\Resources\TalentPools\TalentPoolResource;
use App\Models\TalentPool;
use App\Services\TalentPoolService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTalentPool extends EditRecord
{
    protected static string $resource = TalentPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TalentPool $record */
        return app(TalentPoolService::class)->update($record, $data);
    }
}
