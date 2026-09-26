<?php

namespace App\Filament\Resources\EmployeeSeparations\Pages;

use App\Filament\Resources\EmployeeSeparations\EmployeeSeparationResource;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeSeparation extends EditRecord
{
    protected static string $resource = EmployeeSeparationResource::class;

    /**
     * Notes are a hidden attribute (kept out of serialization and the audit log), so the default
     * fill from attributesToArray() leaves them out — load them explicitly or a save would wipe them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'notes' => $this->record->notes];
    }

    /**
     * The employee of a separation never changes; only the date, reason and notes are corrected.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['employee_id']);

        return $data;
    }
}
