<?php

namespace App\Filament\Resources\CandidateCommunicationPreferences\Actions;

use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Models\Candidate;
use App\Services\Communication\CommunicationPreferenceService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Recruiter-side edit of a candidate's per-channel preferences. Every change goes through
 * CommunicationPreferenceService (audited, on the timeline). A reason is required because a
 * recruiter is recording consent/opt-out on the candidate's behalf.
 */
class EditPreferencesAction
{
    /**
     * @param  Closure(): Candidate  $candidate
     */
    public static function make(Closure $candidate, string $name = 'editPreferences'): Action
    {
        return Action::make($name)
            ->label('Communication preferences')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('communications.preferences') && (bool) auth()->user()?->can('view', $candidate()))
            ->fillForm(fn (): array => collect(app(CommunicationPreferenceService::class)->allFor($candidate()))->map->value->all())
            ->schema([
                ...collect(CommunicationChannel::cases())->map(fn (CommunicationChannel $channel) => Select::make($channel->value)
                    ->label($channel->label())
                    ->options(PreferenceStatus::options())
                    ->helperText($channel->requiresExplicitConsent() ? 'Messages are only sent when Allowed (explicit consent).' : 'Allowed unless Opted out.')
                    ->required())->all(),
                Textarea::make('reason')->label('Reason / how the candidate told us')->required()->maxLength(500),
            ])
            ->action(function (array $data) use ($candidate): void {
                $target = $candidate();
                abort_unless((bool) auth()->user()?->can('communications.preferences') && (bool) auth()->user()?->can('view', $target), 403);

                $service = app(CommunicationPreferenceService::class);

                foreach (CommunicationChannel::cases() as $channel) {
                    $status = PreferenceStatus::from($data[$channel->value]);

                    if ($service->statusFor($target, $channel) !== $status) {
                        $service->set($target, $channel, $status, 'recruiter', auth()->user()?->employee, $data['reason']);
                    }
                }

                Notification::make()->title('Communication preferences updated')->success()->send();
            });
    }
}
