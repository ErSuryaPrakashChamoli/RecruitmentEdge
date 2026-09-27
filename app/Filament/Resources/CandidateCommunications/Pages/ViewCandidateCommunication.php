<?php

namespace App\Filament\Resources\CandidateCommunications\Pages;

use App\Enums\CommunicationStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\CandidateCommunications\CandidateCommunicationResource;
use App\Models\CandidateCommunication;
use App\Services\Communication\CommunicationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCandidateCommunication extends ViewRecord
{
    use GuardsDomainExceptions;

    protected static string $resource = CandidateCommunicationResource::class;

    /**
     * Phase 8.7 (D8.7-007 a): a failed or bounced message can be sent again as a new message, with
     * a reason. Nothing is resent automatically — a duplicate to a candidate is worse than a
     * visible failure.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resend')
                ->label('Resend')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (CandidateCommunication $record): bool => in_array($record->status, [CommunicationStatus::Failed, CommunicationStatus::Bounced], true))
                ->authorize('resend')
                ->modalDescription('A new message with the same content is queued to the candidate\'s current contact details. Consent and the candidate\'s current status are checked again.')
                ->schema([
                    Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                ])
                ->action(function (CandidateCommunication $record, array $data): void {
                    $message = self::guarded('Message could not be resent', fn () => app(CommunicationService::class)->resend($record, auth()->user()->employee, $data['reason']));

                    $message->status === CommunicationStatus::Blocked
                        ? Notification::make()->title('Message not resent')->body($message->blocked_reason)->warning()->persistent()->send()
                        : Notification::make()->title('Message queued again')->success()->send();

                    $this->redirect(CandidateCommunicationResource::getUrl('view', ['record' => $message]));
                }),
        ];
    }
}
