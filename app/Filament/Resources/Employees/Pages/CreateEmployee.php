<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use App\Services\Identity\HierarchyIntegrityService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Phase 8.4: a new employee's manager must be a current employee inside the creator's hierarchy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            app(HierarchyIntegrityService::class)->assertAssignableManager(filled($data['reports_to_id'] ?? null) ? (int) $data['reports_to_id'] : null, $actor);
        } catch (DomainException $e) {
            Notification::make()->title('Employee could not be created')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        return $data;
    }
}
