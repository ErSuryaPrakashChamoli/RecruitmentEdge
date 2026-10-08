<?php

namespace App\Filament\Resources\CandidateJoinings\Tables;

use App\Enums\CandidateStage;
use App\Enums\DocumentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\JoiningStatus;
use App\Filament\Exports\CandidateJoiningExporter;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\CandidateJoiningService;
use App\Services\EmployeeConversionService;
use App\Services\HierarchyService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CandidateJoiningsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('candidateApplication.candidate.full_name')
                    ->label('Candidate')
                    ->html()
                    ->formatStateUsing(fn ($record) => view('filament.tables.columns.person-name', [
                        'name' => $record->candidateApplication->candidate->full_name,
                        'subtitle' => $record->candidateApplication->requisition?->designation?->name,
                    ]))
                    ->searchable(),
                TextColumn::make('candidateApplication.requisition.code')
                    ->label('Position')
                    ->searchable(),
                TextColumn::make('candidateApplication.recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->candidateApplication->recruiter->fullName()),
                TextColumn::make('expected_doj')
                    ->label('Expected DOJ')
                    ->date()
                    ->sortable(),
                TextColumn::make('actual_doj')
                    ->label('Actual DOJ')
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('confirmed_at')
                    ->label('Confirmation')
                    ->dateTime()
                    ->placeholder('Not confirmed'),
                TextColumn::make('risk')
                    ->label('Risk')
                    ->badge()
                    ->state(fn (CandidateJoining $record) => $record->riskLevel())
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'green' => 'On Track',
                        'yellow' => 'Needs Follow-up',
                        'closed' => 'Closed',
                        default => 'High Risk',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'green' => 'success',
                        'yellow' => 'warning',
                        'closed' => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (JoiningStatus $state) => $state->label())
                    ->color(fn (JoiningStatus $state) => $state->color()),
                TextColumn::make('documents_status')
                    ->badge()
                    ->formatStateUsing(fn (DocumentStatus $state) => $state->label())
                    ->color(fn (DocumentStatus $state) => $state->color()),
            ])
            ->defaultSort('expected_doj')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(JoiningStatus::cases())->mapWithKeys(fn (JoiningStatus $s) => [$s->value => $s->label()])),
                Filter::make('expected_doj')
                    ->schema([
                        Select::make('range')
                            ->options([
                                'today' => "Today's Joining",
                                'tomorrow' => "Tomorrow's Joining",
                                'next_7_days' => 'Next 7 Days',
                                'this_month' => 'This Month',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['range'] ?? null) {
                            'today' => $query->whereDate('expected_doj', now()->toDateString()),
                            'tomorrow' => $query->whereDate('expected_doj', now()->addDay()->toDateString()),
                            'next_7_days' => $query->whereBetween('expected_doj', [now()->toDateString(), now()->addDays(7)->toDateString()]),
                            'this_month' => $query->whereBetween('expected_doj', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]),
                            default => $query,
                        };
                    }),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(CandidateJoiningExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export')),
            ])
            ->recordActions([
                self::confirmAction(),
                self::markJoinedAction(),
                self::markNoShowAction(),
                self::markDropoutAction(),
                self::markDocumentsCompletedAction(),
                self::markOnboardingCompletedAction(),
                self::convertToEmployeeAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No joinings tracked')
            ->emptyStateDescription('Joining records are created automatically once an offer is accepted.')
            ->emptyStateIcon('heroicon-o-user-plus');
    }

    private static function confirmAction(): Action
    {
        return Action::make('confirmJoining')
            ->label('Confirm')
            ->color('info')
            ->icon('heroicon-o-check')
            ->visible(fn (CandidateJoining $record) => $record->status === JoiningStatus::Expected)
            ->action(fn (CandidateJoining $record) => self::perform(
                fn (CandidateJoiningService $service) => $service->confirm($record, auth()->user()?->employee),
                'Joining confirmed',
            ));
    }

    private static function markJoinedAction(): Action
    {
        return Action::make('markJoined')
            ->label('Mark Joined')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (CandidateJoining $record) => in_array($record->status, [JoiningStatus::Expected, JoiningStatus::Confirmed], true))
            ->requiresConfirmation()
            ->action(fn (CandidateJoining $record) => self::perform(
                fn (CandidateJoiningService $service) => $service->markJoined($record, actor: auth()->user()?->employee),
                'Candidate marked as joined',
            ));
    }

    private static function markNoShowAction(): Action
    {
        return Action::make('markNoShow')
            ->label('No Show')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (CandidateJoining $record) => in_array($record->status, [JoiningStatus::Expected, JoiningStatus::Confirmed], true))
            ->schema([
                Select::make('reason_id')
                    ->label('Reason')
                    ->options(fn (): array => RecruitmentRejectionReason::groupedActiveOptions())
                    ->required()
                    ->searchable(),
            ])
            ->action(fn (CandidateJoining $record, array $data) => self::perform(
                fn (CandidateJoiningService $service) => $service->markNoShow(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['reason_id']),
                    auth()->user()?->employee,
                ),
                'Marked as no-show',
            ));
    }

    private static function markDropoutAction(): Action
    {
        return Action::make('markDropout')
            ->label('Dropout')
            ->color('danger')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (CandidateJoining $record) => in_array($record->status, [JoiningStatus::Expected, JoiningStatus::Confirmed], true))
            ->schema([
                Select::make('reason_id')
                    ->label('Reason')
                    ->options(fn (): array => RecruitmentRejectionReason::groupedActiveOptions())
                    ->required()
                    ->searchable(),
            ])
            ->action(fn (CandidateJoining $record, array $data) => self::perform(
                fn (CandidateJoiningService $service) => $service->markDropout(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['reason_id']),
                    auth()->user()?->employee,
                ),
                'Marked as dropout',
            ));
    }

    private static function markDocumentsCompletedAction(): Action
    {
        return Action::make('markDocumentsCompleted')
            ->label('Mark Documents Completed')
            ->color('success')
            ->icon('heroicon-o-document-check')
            ->visible(fn (CandidateJoining $record) => $record->status === JoiningStatus::Joined
                && $record->candidateApplication->current_stage->order() < CandidateStage::DocumentsCompleted->order())
            ->requiresConfirmation()
            ->action(fn (CandidateJoining $record) => self::perform(
                fn (CandidateJoiningService $service) => $service->markDocumentsCompleted($record, auth()->user()?->employee),
                'Documents marked as completed',
            ));
    }

    private static function markOnboardingCompletedAction(): Action
    {
        return Action::make('markOnboardingCompleted')
            ->label('Mark Onboarding Completed')
            ->color('success')
            ->icon('heroicon-o-academic-cap')
            ->visible(fn (CandidateJoining $record) => $record->status === JoiningStatus::Joined
                && $record->candidateApplication->current_stage === CandidateStage::DocumentsCompleted)
            ->requiresConfirmation()
            ->action(fn (CandidateJoining $record) => self::perform(
                fn (CandidateJoiningService $service) => $service->markOnboardingCompleted($record, auth()->user()?->employee),
                'Onboarding marked as completed',
            ));
    }

    private static function convertToEmployeeAction(): Action
    {
        return Action::make('convertToEmployee')
            ->label('Convert to Employee')
            ->color('success')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (CandidateJoining $record) => $record->status === JoiningStatus::Joined
                // Phase 8.4: a separated former employee is rehired through the same action.
                && in_array($record->candidateApplication->candidate->employee?->status, [null, EmployeeStatus::Separated], true)
                && (bool) auth()->user()?->can('convert', $record))
            ->requiresConfirmation()
            ->modalDescription('Creates the employee record and their login (base role, set-password email), places them under the chosen manager and closes their candidate portal login.')
            // Phase 8.4: the new employee's manager is part of the conversion (hierarchy scope).
            ->schema([
                Select::make('manager_id')
                    ->label('Reports to')
                    ->options(fn (): array => self::assignableManagers())
                    ->default(fn (CandidateJoining $record): ?int => $record->candidateApplication->requisition?->reporting_manager_id
                        ?? $record->candidateApplication->requisition?->hiring_manager_id
                        ?? $record->candidateApplication->requisition?->manager_id)
                    ->searchable()
                    ->required(),
            ])
            ->action(function (CandidateJoining $record, array $data): void {
                try {
                    $employee = app(EmployeeConversionService::class)->convert($record, auth()->user(), (int) $data['manager_id']);
                } catch (DomainException $e) {
                    Notification::make()->title('Candidate could not be converted')->body($e->getMessage())->danger()->persistent()->send();

                    throw new Halt;
                }

                Notification::make()->title("Converted to employee {$employee->employee_code}")->success()->send();
            });
    }

    /**
     * Current employees inside the converter's hierarchy (EmployeeConversionService re-checks).
     *
     * @return array<int, string>
     */
    private static function assignableManagers(): array
    {
        $user = auth()->user();
        $visible = $user instanceof User ? app(HierarchyService::class)->visibleEmployeeIdsFor($user) : collect();

        return Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->when($visible !== null, fn ($query) => $query->whereIn('id', $visible))
            ->orderBy('first_name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName().' ('.$employee->employee_code.')'])
            ->all();
    }

    /**
     * CandidateJoiningService/StageTransitionService enforce the joining rules as DomainException —
     * surface them as a notification and halt instead of rendering a 500 over the panel.
     *
     * @param  callable(CandidateJoiningService): mixed  $callback
     */
    private static function perform(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(CandidateJoiningService::class));
        } catch (DomainException $e) {
            Notification::make()
                ->title('Joining could not be updated')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
