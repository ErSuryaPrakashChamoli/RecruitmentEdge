<?php

namespace App\Filament\Resources\Offers\Tables;

use App\Enums\OfferStatus;
use App\Filament\Exports\OfferExporter;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Services\Export\ReportExportService;
use App\Services\OfferService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                    ->html()
                    ->formatStateUsing(fn ($record) => view('filament.tables.columns.person-name', [
                        'name' => $record->candidateApplication->candidate->full_name,
                        'subtitle' => $record->designation?->name,
                    ]))
                    ->searchable(),
                TextColumn::make('offered_ctc')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('offer_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('offer_expiry')
                    ->label('Valid Until')
                    ->date()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('expected_joining_date')
                    ->date(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OfferStatus $state) => $state->label())
                    ->color(fn (OfferStatus $state) => $state->color()),
            ])
            ->defaultSort('offer_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OfferStatus::cases())->mapWithKeys(fn (OfferStatus $s) => [$s->value => $s->label()])),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(OfferExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export')),
            ])
            ->recordActions([
                self::releaseAction(),
                self::changeStatusAction(),
                self::downloadOfferLetterAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No offers yet')
            ->emptyStateDescription('Offers released to selected candidates will appear here.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    /**
     * Dedicated action for the higher-trust Released transition — only shown to users holding
     * `offers.release` (OfferPolicy::release). OfferService enforces the same rule server-side.
     */
    public static function releaseAction(): Action
    {
        return Action::make('releaseOffer')
            ->label('Release Offer')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Initiated
                && (bool) auth()->user()?->can('release', $record))
            ->requiresConfirmation()
            ->schema([
                Textarea::make('remarks'),
            ])
            ->action(fn (Offer $record, array $data) => self::performStatusChange($record, OfferStatus::Released, $data));
    }

    private static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change Status')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (Offer $record): bool => app(OfferService::class)->allowedNextStatuses($record) !== []
                && (bool) auth()->user()?->canAny(['update', 'release'], $record))
            ->schema(fn (Offer $record) => [
                Select::make('to_status')
                    ->label('New Status')
                    ->options(self::statusOptionsFor($record))
                    ->live()
                    ->required(),
                Select::make('rejection_reason_id')
                    ->label('Rejection Reason')
                    ->options(fn (): array => RecruitmentRejectionReason::groupedActiveOptions())
                    ->searchable()
                    ->visible(fn (Get $get) => $get('to_status') === OfferStatus::Rejected->value)
                    ->required(fn (Get $get) => $get('to_status') === OfferStatus::Rejected->value),
                Textarea::make('remarks'),
            ])
            ->action(fn (Offer $record, array $data) => self::performStatusChange($record, OfferStatus::from($data['to_status']), $data));
    }

    public static function downloadOfferLetterAction(): Action
    {
        return Action::make('downloadOfferLetter')
            ->label('Download Offer Letter')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (Offer $record): bool => (bool) auth()->user()?->can('view', $record))
            ->action(function (Offer $record): StreamedResponse {
                $record->loadMissing([
                    'candidateApplication.candidate',
                    'candidateApplication.requisition.department',
                    'candidateApplication.requisition.designation',
                    'candidateApplication.requisition.location',
                    'designation',
                    'location',
                ]);

                return app(ReportExportService::class)->streamPdf(
                    "offer-letter-{$record->offer_code}.pdf",
                    'pdf.offer-letter',
                    ['offer' => $record],
                );
            });
    }

    /**
     * Released is only offered to users who may release (OfferPolicy::release).
     *
     * @return array<string, string>
     */
    public static function statusOptionsFor(Offer $record): array
    {
        $canRelease = (bool) auth()->user()?->can('release', $record);

        return collect(app(OfferService::class)->allowedNextStatuses($record))
            ->reject(fn (OfferStatus $s): bool => $s === OfferStatus::Released && ! $canRelease)
            ->mapWithKeys(fn (OfferStatus $s) => [$s->value => $s->label()])
            ->all();
    }

    /**
     * OfferService enforces the transition rules (allowed moves, release permission, rejection
     * reason) as DomainException — surface them as a notification and halt the action instead of
     * rendering a 500 over the panel.
     *
     * @param  array<string, mixed>  $data
     */
    public static function performStatusChange(Offer $record, OfferStatus $to, array $data): void
    {
        try {
            app(OfferService::class)->moveTo(
                $record,
                $to,
                auth()->user()?->employee,
                $data['remarks'] ?? null,
                filled($data['rejection_reason_id'] ?? null)
                    ? RecruitmentRejectionReason::query()->find($data['rejection_reason_id'])
                    : null,
            );
        } catch (DomainException $e) {
            Notification::make()
                ->title('Offer status could not be changed')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        Notification::make()
            ->title($to === OfferStatus::Released ? 'Offer released' : 'Offer status updated')
            ->success()
            ->send();
    }
}
