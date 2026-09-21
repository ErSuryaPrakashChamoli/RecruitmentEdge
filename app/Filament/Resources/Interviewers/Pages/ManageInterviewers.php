<?php

namespace App\Filament\Resources\Interviewers\Pages;

use App\Filament\Resources\Interviewers\InterviewerResource;
use App\Services\InterviewerImportService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
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

    private function performImport(string $storedPath): void
    {
        try {
            $result = app(InterviewerImportService::class)->import(Storage::disk('local')->path($storedPath));
        } catch (DomainException $exception) {
            Notification::make()->danger()->title('Import failed')->body($exception->getMessage())->send();

            throw new Halt;
        } finally {
            Storage::disk('local')->delete($storedPath);
        }

        $skipped = collect($result['skipped']);

        Notification::make()
            ->title("{$result['added']} interviewer(s) added")
            ->body(collect([
                $result['already_listed'] > 0 ? "{$result['already_listed']} already on the list." : null,
                $skipped->isNotEmpty() ? "{$skipped->count()} skipped: ".$skipped->take(10)->implode('; ') : null,
            ])->filter()->implode(' '))
            ->when($skipped->isNotEmpty(), fn (Notification $notification) => $notification->warning(), fn (Notification $notification) => $notification->success())
            ->send();
    }
}
