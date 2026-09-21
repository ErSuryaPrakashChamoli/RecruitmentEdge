<?php

namespace App\Filament\Resources\Offers\Tables;

use App\Enums\OfferLetterTemplateFormat;
use App\Enums\OfferStatus;
use App\Filament\Exports\OfferExporter;
use App\Models\Offer;
use App\Models\OfferLetterTemplate;
use App\Models\RecruitmentRejectionReason;
use App\Services\OfferLetterRenderer;
use App\Services\OfferService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OffersTable
{
    /**
     * The letter can be tailored until the candidate has decided on the offer.
     */
    private const LETTER_EDITABLE_STATUSES = [
        OfferStatus::Draft,
        OfferStatus::Initiated,
        OfferStatus::Released,
    ];

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
                self::customizeOfferLetterAction(),
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

    /**
     * Tailors the letter for this one candidate: start from a template, then edit the wording. Merge
     * tags stay live, so later changes to the offer's salary or dates still flow into the PDF.
     */
    public static function customizeOfferLetterAction(): Action
    {
        return Action::make('customizeOfferLetter')
            ->label('Customize Offer Letter')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (Offer $record): bool => in_array($record->status, self::LETTER_EDITABLE_STATUSES, true)
                && (bool) auth()->user()?->can('update', $record))
            ->modalWidth('5xl')
            ->fillForm(function (Offer $record): array {
                $template = $record->offerLetterTemplate ?? OfferLetterTemplate::defaultTemplate();
                $richTextTemplate = $template?->format === OfferLetterTemplateFormat::RichText ? $template : null;

                return [
                    'offer_letter_template_id' => $richTextTemplate?->getKey(),
                    'offer_letter_body' => filled($record->offer_letter_body) ? $record->offer_letter_body : $richTextTemplate?->body,
                ];
            })
            ->schema([
                Select::make('offer_letter_template_id')
                    ->label('Start from template')
                    ->options(fn (): array => OfferLetterTemplate::query()
                        ->where('is_active', true)
                        ->where('format', OfferLetterTemplateFormat::RichText)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText('Choosing a template replaces the letter below with that template\'s wording. A letter written here is used for this offer instead of any Word template.')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, mixed $state) => $set('offer_letter_body', OfferLetterTemplate::query()->find($state)?->body)),
                RichEditor::make('offer_letter_body')
                    ->label('Letter')
                    ->mergeTags(OfferLetterRenderer::MERGE_TAGS)
                    ->helperText('Insert merge tags such as Candidate name or Offered CTC from the editor — they are filled from this offer when the PDF is generated.')
                    ->required(),
            ])
            ->action(function (Offer $record, array $data): void {
                $record->update([
                    'offer_letter_template_id' => $data['offer_letter_template_id'] ?? null,
                    'offer_letter_body' => $data['offer_letter_body'],
                ]);

                Notification::make()->title('Offer letter saved')->success()->send();
            });
    }

    /**
     * Discards the offer's tailored wording so the letter follows its template again.
     */
    public static function resetOfferLetterAction(): Action
    {
        return Action::make('resetOfferLetter')
            ->label('Reset Letter to Template')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('This discards the wording customised for this offer; the letter will use its template again.')
            ->visible(fn (Offer $record): bool => filled($record->offer_letter_body)
                && in_array($record->status, self::LETTER_EDITABLE_STATUSES, true)
                && (bool) auth()->user()?->can('update', $record))
            ->action(function (Offer $record): void {
                $record->update(['offer_letter_body' => null]);

                Notification::make()->title('Offer letter reset to template')->success()->send();
            });
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
                    'offerLetterTemplate',
                ]);

                // Candidates always receive a PDF, whatever format the template is maintained in.
                $pdf = app(OfferLetterRenderer::class)->pdf($record);

                return response()->streamDownload(function () use ($pdf): void {
                    echo $pdf;
                }, "offer-letter-{$record->offer_code}.pdf", ['Content-Type' => 'application/pdf']);
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
