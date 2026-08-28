<?php

namespace App\Filament\Resources\CandidateJoinings\Tables;

use App\Enums\DocumentStatus;
use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\RecruitmentRejectionReason;
use App\Services\CandidateJoiningService;
use App\Services\EmployeeConversionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
                TextColumn::make('risk')
                    ->label('Risk')
                    ->state(fn (CandidateJoining $record) => match ($record->riskLevel()) {
                        'green' => '🟢 Confirmed',
                        'yellow' => '🟡 Needs Follow-up',
                        default => '🔴 High Risk',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (JoiningStatus $state) => $state->label())
                    ->color(fn (JoiningStatus $state) => match ($state) {
                        JoiningStatus::Joined => 'success',
                        JoiningStatus::Confirmed => 'info',
                        JoiningStatus::NoShow, JoiningStatus::Dropout => 'danger',
                        JoiningStatus::Expected => 'gray',
                    }),
                TextColumn::make('documents_status')
                    ->badge()
                    ->formatStateUsing(fn (DocumentStatus $state) => $state->label()),
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
            ->recordActions([
                self::confirmAction(),
                self::markJoinedAction(),
                self::markNoShowAction(),
                self::markDropoutAction(),
                self::convertToEmployeeAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function confirmAction(): Action
    {
        return Action::make('confirmJoining')
            ->label('Confirm')
            ->color('info')
            ->icon('heroicon-o-check')
            ->visible(fn (CandidateJoining $record) => $record->status === JoiningStatus::Expected)
            ->action(function (CandidateJoining $record): void {
                app(CandidateJoiningService::class)->confirm($record, auth()->user()?->employee);
                Notification::make()->title('Joining confirmed')->success()->send();
            });
    }

    private static function markJoinedAction(): Action
    {
        return Action::make('markJoined')
            ->label('Mark Joined')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (CandidateJoining $record) => in_array($record->status, [JoiningStatus::Expected, JoiningStatus::Confirmed], true))
            ->requiresConfirmation()
            ->action(function (CandidateJoining $record): void {
                app(CandidateJoiningService::class)->markJoined($record, actor: auth()->user()?->employee);
                Notification::make()->title('Candidate marked as joined')->success()->send();
            });
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
                    ->options(RecruitmentRejectionReason::query()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
            ])
            ->action(function (CandidateJoining $record, array $data): void {
                app(CandidateJoiningService::class)->markNoShow(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['reason_id']),
                    auth()->user()?->employee,
                );
                Notification::make()->title('Marked as no-show')->success()->send();
            });
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
                    ->options(RecruitmentRejectionReason::query()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
            ])
            ->action(function (CandidateJoining $record, array $data): void {
                app(CandidateJoiningService::class)->markDropout(
                    $record,
                    RecruitmentRejectionReason::query()->findOrFail($data['reason_id']),
                    auth()->user()?->employee,
                );
                Notification::make()->title('Marked as dropout')->success()->send();
            });
    }

    private static function convertToEmployeeAction(): Action
    {
        return Action::make('convertToEmployee')
            ->label('Convert to Employee')
            ->color('success')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (CandidateJoining $record) => $record->status === JoiningStatus::Joined
                && $record->candidateApplication->candidate->employee === null)
            ->requiresConfirmation()
            ->action(function (CandidateJoining $record): void {
                $employee = app(EmployeeConversionService::class)->convert($record);
                Notification::make()->title("Converted to employee {$employee->employee_code}")->success()->send();
            });
    }
}
