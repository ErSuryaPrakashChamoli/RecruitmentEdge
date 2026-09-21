<?php

namespace App\Filament\Resources\OfferLetterTemplates\Actions;

use App\Models\OfferLetterTemplate;
use App\Services\OfferLetterRenderer;
use App\Services\StandardOfferLetterDocument;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The download → edit in Word → upload workflow for Word offer letter templates, and restoring the
 * protected standard template's original file. Shared by the templates table and record pages.
 */
class OfferLetterTemplateActions
{
    /**
     * MIME types a .docx upload may be detected as; its content is then verified by opening it as a
     * Word template (see guardPlaceholders()).
     *
     * @var array<int, string>
     */
    public const WORD_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ];

    public static function downloadWordFile(): Action
    {
        return Action::make('downloadWordFile')
            ->label('Download Word File')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (OfferLetterTemplate $record): bool => $record->isWord()
                && $record->hasFile()
                && (bool) auth()->user()?->can('view', $record))
            ->action(fn (OfferLetterTemplate $record): StreamedResponse => Storage::disk('local')
                ->download((string) $record->file_path, $record->downloadFileName()));
    }

    public static function uploadWordFile(): Action
    {
        return Action::make('uploadWordFile')
            ->label('Upload New Version')
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (OfferLetterTemplate $record): bool => $record->isWord()
                && (bool) auth()->user()?->can('update', $record))
            ->modalDescription('Upload the edited Word (.docx) file. It replaces the current file; placeholders such as ${candidate_name} are filled from each offer.')
            ->schema([
                self::wordFileUpload('file')
                    ->helperText('Allowed placeholders: '.self::placeholderList().'.')
                    ->required(),
            ])
            ->action(function (OfferLetterTemplate $record, array $data): void {
                self::guardPlaceholders($data['file']);

                $record->update(['file_path' => $data['file']]);

                Notification::make()->title('Word template updated')->success()->send();
            });
    }

    public static function restoreOriginal(): Action
    {
        return Action::make('restoreOriginal')
            ->label('Restore Original')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Replaces the current Word file with the original standard offer letter. Changes made to this template\'s file are lost.')
            ->visible(fn (OfferLetterTemplate $record): bool => $record->is_system
                && (bool) auth()->user()?->can('update', $record))
            ->action(function (OfferLetterTemplate $record): void {
                $path = app(StandardOfferLetterDocument::class)
                    ->store(OfferLetterTemplate::FILE_DIRECTORY.'/standard-offer-letter-'.Str::uuid().'.docx');

                $record->update(['file_path' => $path]);

                Notification::make()->title('Original offer letter restored')->success()->send();
            });
    }

    /**
     * The .docx upload field, stored privately on the local disk.
     */
    public static function wordFileUpload(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->label('Word file (.docx)')
            ->disk('local')
            ->directory(OfferLetterTemplate::FILE_DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(self::WORD_MIME_TYPES)
            ->maxSize(10240);
    }

    /**
     * Rejects a stored upload that is not a readable Word file or uses placeholders an offer cannot
     * fill: the upload is deleted, the admin is told why, and the action halts.
     */
    public static function guardPlaceholders(string $storedPath): void
    {
        try {
            $unknown = app(OfferLetterRenderer::class)->unknownPlaceholdersIn(Storage::disk('local')->path($storedPath));
        } catch (DomainException $exception) {
            Storage::disk('local')->delete($storedPath);

            Notification::make()
                ->title('Invalid Word file')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        if ($unknown !== []) {
            Storage::disk('local')->delete($storedPath);

            Notification::make()
                ->title('Unknown placeholders in the Word file')
                ->body('These placeholders cannot be filled from an offer: '
                    .collect($unknown)->map(fn (string $tag): string => '${'.$tag.'}')->implode(', ')
                    .'. Allowed placeholders: '.self::placeholderList().'.')
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    public static function placeholderList(): string
    {
        return collect(array_keys(OfferLetterRenderer::MERGE_TAGS))
            ->map(fn (string $tag): string => '${'.$tag.'}')
            ->implode(', ');
    }
}
