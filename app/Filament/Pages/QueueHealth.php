<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Services\PlatformAlertService;
use App\Services\QueueHealthService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Phase 8.7 (D8.7-012/021): the state of the queues, failed jobs (redacted), work left stuck and the
 * scheduler's heartbeat, for platform administrators (settings.manage). Read-only apart from
 * retrying a failed job, which is audited with a reason. A retried job re-checks its own state —
 * a message already failed or sent is not sent again.
 */
class QueueHealth extends Page
{
    protected string $view = 'filament.pages.queue-health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Queue health';

    protected static ?string $title = 'Queue health';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can(PlatformAlertService::PERMISSION);
    }

    /**
     * @return array<string, mixed>
     */
    public function getHealth(): array
    {
        return app(QueueHealthService::class)->snapshot();
    }

    public function retryFailedJobAction(): Action
    {
        return Action::make('retryFailedJob')
            ->label('Retry')
            ->modalHeading('Retry this failed job')
            ->modalDescription('The job is put back on its queue. It checks its own record again when it runs, so work that has already happened is not repeated.')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->action(function (array $arguments, array $data): void {
                abort_unless(static::canAccess(), 403);

                $failed = DB::table('failed_jobs')->where('uuid', (string) ($arguments['uuid'] ?? ''))->first(['id', 'uuid', 'queue', 'payload']);

                if ($failed === null) {
                    Notification::make()->title('That failed job no longer exists')->warning()->send();

                    return;
                }

                $job = (string) (json_decode((string) $failed->payload, true)['displayName'] ?? 'unknown');
                Artisan::call('queue:retry', ['id' => [$failed->uuid]]);

                AuditLog::record(auth()->user(), 'failed_job_retried', null, ['uuid' => $failed->uuid, 'queue' => $failed->queue, 'job' => $job], $data['reason']);

                Notification::make()->title('Job queued again')->body($job)->success()->send();
            });
    }
}
