<?php

namespace App\Filament\Resources\Offers\RelationManagers;

use App\Enums\OfferRevisionStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Services\OfferService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Phase 8.3: every set of terms released or proposed for the offer. Revisions are requested from
 * the offer ("Request revision"), released here by someone with offers.release, or cancelled —
 * never edited. Terms are visible to whoever may manage the offer, like the offer itself.
 */
class RevisionsRelationManager extends RelationManager
{
    use GuardsDomainExceptions;

    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Revisions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('revision')
            ->columns([
                TextColumn::make('revision')->prefix('#'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (OfferRevisionStatus $state) => $state->label())->color(fn (OfferRevisionStatus $state) => $state->color()),
                TextColumn::make('offered_ctc')->label('Offered CTC')->numeric()->visible(fn (): bool => (bool) auth()->user()?->can('compensation.view')),
                TextColumn::make('fixed_salary')->numeric()->toggleable(),
                TextColumn::make('expected_joining_date')->date()->toggleable(),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('requester.name')->label('Requested by')->placeholder('—'),
                TextColumn::make('decided_at')->label('Decided')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('releaseRevision')
                    ->label('Release')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (OfferRevision $record): bool => $record->status === OfferRevisionStatus::Pending && (bool) auth()->user()?->can('release', $this->offer()))
                    ->requiresConfirmation()
                    ->modalDescription('The revised terms replace the released terms; the earlier release stays on record as superseded.')
                    ->schema([Textarea::make('remarks')->rows(2)])
                    ->action(function (OfferRevision $record, array $data): void {
                        self::guarded('Revision could not be released', fn () => app(OfferService::class)->releaseRevision($record, auth()->user(), $data['remarks'] ?? null));
                        Notification::make()->title('Revised offer released')->success()->send();
                    }),
                Action::make('cancelRevision')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (OfferRevision $record): bool => $record->status === OfferRevisionStatus::Pending && (bool) auth()->user()?->can('update', $this->offer()))
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(255)])
                    ->action(function (OfferRevision $record, array $data): void {
                        self::guarded('Revision could not be cancelled', fn () => app(OfferService::class)->cancelRevision($record, auth()->user(), $data['reason']));
                        Notification::make()->title('Revision cancelled')->success()->send();
                    }),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }

    private function offer(): Offer
    {
        /** @var Offer $offer */
        $offer = $this->getOwnerRecord();

        return $offer;
    }
}
