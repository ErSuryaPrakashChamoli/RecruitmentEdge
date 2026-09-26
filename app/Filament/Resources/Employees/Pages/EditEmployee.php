<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Pages\AiCopilot;
use App\Filament\Resources\Employees\Actions\EmploymentActions;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\HierarchyIntegrityService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('askAi')
                ->label('Analyze with AI')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn () => (bool) auth()->user()?->can('ai.query'))
                ->url(fn () => AiCopilot::linkForContext('employee', $this->record->id)),
            EmploymentActions::deactivate(),
            EmploymentActions::reactivate(),
            // Phase 8.4: soft delete / restore through HierarchyIntegrityService; permanent deletion
            // is not offered (attribution and the hierarchy must stay intact).
            DeleteAction::make()->using(fn (Employee $record) => self::throughService(fn (HierarchyIntegrityService $service, User $actor) => $service->delete($record, $actor))),
            RestoreAction::make()->using(fn (Employee $record) => self::throughService(fn (HierarchyIntegrityService $service, User $actor) => $service->restore($record, $actor))),
        ];
    }

    /**
     * Phase 8.4: a changed reporting line goes through HierarchyIntegrityService (scope, cycle and
     * protected-role checks) before the rest of the record is saved.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $managerId = Arr::pull($data, 'reports_to_id');

        self::throughService(fn (HierarchyIntegrityService $service, User $actor) => DB::transaction(function () use ($service, $actor, $record, $managerId, $data): void {
            $service->reassign($record, filled($managerId) ? (int) $managerId : null, $actor);
            $record->update($data);
        }));

        return $record;
    }

    /**
     * @param  callable(HierarchyIntegrityService, User): mixed  $operation
     */
    private static function throughService(callable $operation): bool
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation(app(HierarchyIntegrityService::class), $actor);
        } catch (DomainException $e) {
            Notification::make()->title('Employee could not be changed')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        return true;
    }
}
