<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Tables;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Models\User;
use App\Services\RecruitmentActivityService;
use DomainException;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class RecruitmentDailyActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('activity_datetime')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->recruiter->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('candidate.full_name')
                    ->label('Candidate')
                    ->searchable(),
                TextColumn::make('activity_type')
                    ->badge()
                    ->formatStateUsing(fn (ActivityType $state) => $state->label()),
                TextColumn::make('outcome')
                    ->badge()
                    ->formatStateUsing(fn (?ActivityOutcome $state) => $state?->label() ?? '—')
                    ->color(fn (?ActivityOutcome $state) => $state?->color() ?? 'gray'),
                TextColumn::make('remarks')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->defaultSort('activity_datetime', 'desc')
            ->filters([
                SelectFilter::make('activity_type')
                    ->options(collect(ActivityType::cases())->mapWithKeys(fn (ActivityType $t) => [$t->value => $t->label()])),
                SelectFilter::make('outcome')
                    ->options(collect(ActivityOutcome::cases())->mapWithKeys(fn (ActivityOutcome $o) => [$o->value => $o->label()])),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Phase 8.5 (SEC-4): each deletion goes through RecruitmentActivityService (team
                    // scope, incentive lock, audit); locked rows are left and reported.
                    DeleteBulkAction::make()
                        ->using(function (Collection $records): void {
                            /** @var User $user */
                            $user = Filament::auth()->user();
                            $refused = 0;

                            foreach ($records as $record) {
                                try {
                                    app(RecruitmentActivityService::class)->delete($user, $record);
                                } catch (DomainException) {
                                    $refused++;
                                }
                            }

                            if ($refused > 0) {
                                Notification::make()->title("{$refused} activit(ies) could not be deleted (outside your team or in an approved incentive period).")->warning()->send();
                            }
                        }),
                ]),
            ]);
    }
}
