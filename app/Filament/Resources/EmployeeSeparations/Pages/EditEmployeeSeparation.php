<?php

namespace App\Filament\Resources\EmployeeSeparations\Pages;

use App\Filament\Resources\EmployeeSeparations\EmployeeSeparationResource;
use App\Models\EmployeeSeparation;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

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
     * The employee of a separation never changes; the date, reason and notes are corrected
     * through EmployeeLifecycleService (Phase 8.4: the date is fixed once the separation applied).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['employee_id'], $data['revoke_access_now']);
        $user = auth()->user();
        abort_unless($record instanceof EmployeeSeparation && $user instanceof User, 403);

        try {
            return app(EmployeeLifecycleService::class)->correctSeparation($record, $user, $data);
        } catch (DomainException $e) {
            Notification::make()->title('Separation could not be corrected')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
