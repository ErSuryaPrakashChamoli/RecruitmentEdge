<?php

namespace App\Filament\Resources\HiringOutcomes\Pages;

use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\HiringOutcomes\HiringOutcomeResource;
use App\Models\HiringOutcome;
use App\Services\Outcomes\OutcomeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewHiringOutcome extends ViewRecord
{
    use GuardsDomainExceptions;

    protected static string $resource = HiringOutcomeResource::class;

    protected function getHeaderActions(): array
    {
        /** @var HiringOutcome $record */
        $record = $this->getRecord();
        $canManage = fn (): bool => auth()->user()?->can('manage', $record) ?? false;

        return [
            Action::make('confirm')
                ->label('Confirm')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $canManage() && $record->state === OutcomeState::Observed)
                ->requiresConfirmation()
                ->modalDescription('Records that a person has checked this outcome. Creates a new, audited version.')
                ->action(function () use ($record): void {
                    $next = self::guarded('Could not confirm the outcome', fn () => app(OutcomeService::class)->confirm($record, auth()->user()));
                    $this->done("Confirmed as v{$next->version}", $next);
                }),
            Action::make('correct')
                ->label('Record a correction')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn () => $canManage() && $record->state !== OutcomeState::Void)
                ->schema([
                    Select::make('result')->label('Correct result')->options(OutcomeResult::options())->default($record->result->value)->required(),
                    TextInput::make('value')->label('Correct value')->numeric()->visible($record->unit !== null)->suffix($record->unit),
                    Textarea::make('reason')->required()->rows(2)->maxLength(255),
                ])
                ->modalDescription('Creates a confirmed, manually corrected version. Automatic re-evaluation never overrides it. The current version is kept as history.')
                ->action(function (array $data) use ($record): void {
                    $next = self::guarded('Could not record the correction', fn () => app(OutcomeService::class)->correct($record, OutcomeResult::from($data['result']), filled($data['value'] ?? null) ? (float) $data['value'] : null, $data['reason'], auth()->user()));
                    $this->done("Correction recorded as v{$next->version}", $next);
                }),
            Action::make('void')
                ->label('Void')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn () => $canManage() && $record->state !== OutcomeState::Void)
                ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(255)])
                ->modalDescription('A voided outcome is left out of every metric. The current version is kept as history.')
                ->action(function (array $data) use ($record): void {
                    $next = self::guarded('Could not void the outcome', fn () => app(OutcomeService::class)->void($record, $data['reason'], auth()->user()));
                    $this->done("Voided as v{$next->version}", $next);
                }),
        ];
    }

    private function done(string $title, HiringOutcome $next): void
    {
        Notification::make()->title($title)->success()->send();
        $this->redirect(HiringOutcomeResource::getUrl('view', ['record' => $next]));
    }
}
