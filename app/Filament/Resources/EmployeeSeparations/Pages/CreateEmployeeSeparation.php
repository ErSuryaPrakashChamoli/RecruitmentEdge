<?php

namespace App\Filament\Resources\EmployeeSeparations\Pages;

use App\Filament\Resources\EmployeeSeparations\EmployeeSeparationResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeSeparation extends CreateRecord
{
    protected static string $resource = EmployeeSeparationResource::class;

    /**
     * Server-side re-check of the employee picked (the option list is only a convenience).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $employee = Employee::query()->find($data['employee_id'] ?? null);
        $user = auth()->user();

        abort_unless($employee !== null && $user instanceof User && app(HierarchyService::class)->canView($user, $employee), 403);

        return $data;
    }
}
