<?php

namespace App\Filament\Resources\Offers\Tables;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Services\OfferService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OffersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('offer_code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('candidateApplication.candidate.full_name')
                    ->label('Candidate')
                    ->searchable(),
                TextColumn::make('offered_ctc')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('offer_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('expected_joining_date')
                    ->date(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OfferStatus $state) => $state->label())
                    ->color(fn (OfferStatus $state) => match ($state) {
                        OfferStatus::Accepted => 'success',
                        OfferStatus::Rejected, OfferStatus::Withdrawn, OfferStatus::Expired => 'danger',
                        OfferStatus::Draft => 'gray',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('offer_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OfferStatus::cases())->mapWithKeys(fn (OfferStatus $s) => [$s->value => $s->label()])),
            ])
            ->recordActions([
                self::changeStatusAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change Status')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (Offer $record): bool => (bool) auth()->user()?->canAny(['update', 'release'], $record))
            ->schema(fn (Offer $record) => [
                Select::make('to_status')
                    ->label('New Status')
                    ->options(collect(app(OfferService::class)->allowedNextStatuses($record))
                        ->mapWithKeys(fn (OfferStatus $s) => [$s->value => $s->label()])
                        ->all())
                    ->live()
                    ->required(),
                Select::make('rejection_reason_id')
                    ->label('Rejection Reason')
                    ->options(RecruitmentRejectionReason::query()->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (Get $get) => $get('to_status') === OfferStatus::Rejected->value)
                    ->required(fn (Get $get) => $get('to_status') === OfferStatus::Rejected->value),
                Textarea::make('remarks'),
            ])
            ->action(function (Offer $record, array $data): void {
                app(OfferService::class)->moveTo(
                    $record,
                    OfferStatus::from($data['to_status']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                    filled($data['rejection_reason_id'] ?? null)
                        ? RecruitmentRejectionReason::query()->find($data['rejection_reason_id'])
                        : null,
                );

                Notification::make()->title('Offer status updated')->success()->send();
            });
    }
}
