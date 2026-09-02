<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\RecruiterPerformanceSnapshots\RecruiterPerformanceSnapshotResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * A read-only visualization of HierarchyService's existing closure-table hierarchy, plus a single
 * controlled reassignment action — HierarchyService, the employee_hierarchy closure table, and
 * EmployeeObserver's incremental maintenance of it are all preserved untouched (Section 32). Node
 * "inspect" links navigate to the existing scoped resources rather than duplicating their data.
 */
class OrganizationHierarchy extends Page
{
    protected string $view = 'filament.pages.organization-hierarchy';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Organization / Hierarchy';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && ((bool) $user->can('hierarchy.view-all') || $user->employee_id !== null);
    }

    /**
     * @return array<int, array{employee: Employee, team_size: int, children: array<int, array<string, mixed>>}>
     */
    public function getTrees(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $hierarchy = app(HierarchyService::class);

        if ($user->can('hierarchy.view-all')) {
            return Employee::query()
                ->whereNull('reports_to_id')
                ->get()
                ->map(fn (Employee $root) => $hierarchy->treeFor($root->id))
                ->filter()
                ->values()
                ->all();
        }

        if ($user->employee_id === null) {
            return [];
        }

        $tree = $hierarchy->treeFor($user->employee_id);

        return $tree !== null ? [$tree] : [];
    }

    public function canReassign(): bool
    {
        return (bool) Filament::auth()->user()?->can('hierarchy.reassign');
    }

    public function candidatesUrl(int $employeeId): string
    {
        return CandidateApplicationResource::getUrl('index', ['tableFilters' => ['recruiter' => ['value' => $employeeId]]]);
    }

    public function vacanciesUrl(int $employeeId): string
    {
        return RecruitmentRequisitionResource::getUrl('index', ['tableFilters' => ['manager' => ['value' => $employeeId]]]);
    }

    public function performanceUrl(int $employeeId): string
    {
        return RecruiterPerformanceSnapshotResource::getUrl('index', ['tableFilters' => ['employee' => ['value' => $employeeId]]]);
    }

    public function reassignManagerAction(): Action
    {
        return Action::make('reassignManager')
            ->label('Reassign Manager')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (): bool => $this->canReassign())
            ->schema(function (array $arguments) {
                $employee = Employee::query()->findOrFail($arguments['employeeId']);

                return [
                    Select::make('reports_to_id')
                        ->label('New Manager')
                        ->options(Employee::query()
                            ->where('id', '!=', $employee->id)
                            ->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()]))
                        ->searchable()
                        ->default($employee->reports_to_id)
                        ->required(),
                ];
            })
            ->action(function (array $arguments, array $data): void {
                abort_unless($this->canReassign(), 403);

                $employee = Employee::query()->findOrFail($arguments['employeeId']);
                $employee->update(['reports_to_id' => $data['reports_to_id']]);

                Notification::make()->title('Manager reassigned')->success()->send();
            });
    }
}
