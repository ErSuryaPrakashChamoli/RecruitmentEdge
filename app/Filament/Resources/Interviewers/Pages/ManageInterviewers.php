<?php

namespace App\Filament\Resources\Interviewers\Pages;

use App\Filament\Resources\Interviewers\InterviewerResource;
use App\Jobs\ImportInterviewersJob;
use App\Services\InterviewerImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageInterviewers extends ManageRecords
{
    protected static string $resource = InterviewerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label('Download template')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    fn () => app(InterviewerImportService::class)->writeTemplate(),
                    'interviewers-template.xlsx',
                )),
            Action::make('importInterviewers')
                ->label('Import from Excel')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->authorize('create', InterviewerResource::getModel())
                ->modalDescription('Upload a sheet with Emp ID, Name and Designation columns. Employees are matched by Emp ID and added to the interviewer list.')
                ->schema([
                    FileUpload::make('file')
                        ->label('Excel file (.xlsx, .xls or .csv)')
                        ->disk('local')
                        ->directory('interviewer-imports')
                        ->visibility('private')
                        ->rules(['extensions:xlsx,xls,csv'])
                        ->maxSize(5120)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->performImport($data['file'])),
            CreateAction::make()
                ->label('Add interviewer'),
        ];
    }

    /**
     * Phase 8.9 (P89-PERF-024): the sheet is imported on the documents queue; the summary arrives as an alert.
     */
    private function performImport(string $storedPath): void
    {
        ImportInterviewersJob::dispatch($storedPath, (int) auth()->id());

        Notification::make()
            ->info()
            ->title('Import started')
            ->body('The spreadsheet is being imported. You will be notified when it finishes.')
            ->send();
    }
}
