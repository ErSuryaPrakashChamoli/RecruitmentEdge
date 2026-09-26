<?php

namespace App\Filament\Resources\EmployeeSeparations\Pages;

use App\Filament\Resources\EmployeeSeparations\EmployeeSeparationResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateEmployeeSeparation extends CreateRecord
{
    protected static string $resource = EmployeeSeparationResource::class;

    /**
     * Phase 8.4: recorded through EmployeeLifecycleService, which re-checks the permission and the
     * hierarchy (the option list is only a convenience), refuses self-separation and the last CHRO,
     * and applies the separation at once only if its last working day has already passed.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $employee = Employee::query()->find($data['employee_id'] ?? null);
        $user = auth()->user();
        abort_unless($employee !== null && $user instanceof User, 403);

        try {
            return app(EmployeeLifecycleService::class)->recordSeparation($employee, $user, $data);
        } catch (DomainException $e) {
            Notification::make()->title('Separation could not be recorded')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
