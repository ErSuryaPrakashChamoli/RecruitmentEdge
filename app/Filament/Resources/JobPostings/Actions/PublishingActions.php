<?php

namespace App\Filament\Resources\JobPostings\Actions;

use App\Enums\Entitlement;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\JobPosting;
use App\Services\Distribution\JobBoardRegistry;
use App\Services\Distribution\JobDistributionService;
use App\Services\Entitlements\EntitlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Publish / pause / unpublish / republish — thin wrappers over JobDistributionService.
 */
class PublishingActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::publish(), self::pause(), self::republish(), self::unpublish()];
    }

    public static function publish(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon('heroicon-o-megaphone')
            ->color('success')
            ->visible(fn (JobPosting $record): bool => $record->status !== JobPostingStatus::Paused && (bool) auth()->user()?->can('update', $record))
            ->schema([
                CheckboxList::make('channels')
                    ->options(fn (): array => app(JobBoardRegistry::class)->options())
                    // SaaS-3: external boards only when the tenant's plan includes them (the
                    // service refuses them anyway); the careers site always.
                    ->disableOptionWhen(fn (string $value): bool => $value !== JobDistributionService::CAREER_SITE && ! app(EntitlementService::class)->allows(Entitlement::DistributionJobBoards))
                    ->default(['career_site'])
                    ->helperText('Channels marked "not configured" have no API access — publishing there is recorded as failed, never faked.')
                    ->required(),
            ])
            ->action(function (JobPosting $record, array $data): void {
                $queued = InterviewsTable::guarded('Posting could not be published', fn () => app(JobDistributionService::class)->publish($record, $data['channels'], auth()->user()?->employee));

                Notification::make()->title($queued->isEmpty() ? 'Already published on the selected channels' : 'Publishing to '.$queued->count().' channel(s)')->success()->send();
            });
    }

    public static function pause(): Action
    {
        return Action::make('pause')
            ->label('Pause')
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (JobPosting $record): bool => $record->status === JobPostingStatus::Published && (bool) auth()->user()?->can('update', $record))
            ->action(function (JobPosting $record): void {
                InterviewsTable::guarded('Posting could not be paused', fn () => app(JobDistributionService::class)->pause($record, auth()->user()?->employee));
                Notification::make()->title('Posting paused')->success()->send();
            });
    }

    public static function republish(): Action
    {
        return Action::make('republish')
            ->label('Republish')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (JobPosting $record): bool => in_array($record->status, [JobPostingStatus::Paused, JobPostingStatus::Closed], true) && (bool) auth()->user()?->can('update', $record))
            ->action(function (JobPosting $record): void {
                InterviewsTable::guarded('Posting could not be republished', fn () => app(JobDistributionService::class)->republish($record, auth()->user()?->employee));
                Notification::make()->title('Republishing')->success()->send();
            });
    }

    public static function unpublish(): Action
    {
        return Action::make('unpublish')
            ->label('Unpublish')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (JobPosting $record): bool => in_array($record->status, [JobPostingStatus::Published, JobPostingStatus::Paused], true) && (bool) auth()->user()?->can('update', $record))
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(function (JobPosting $record, array $data): void {
                app(JobDistributionService::class)->unpublish($record, null, auth()->user()?->employee, $data['reason']);
                Notification::make()->title('Posting unpublished')->success()->send();
            });
    }
}
