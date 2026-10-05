<?php

namespace App\Jobs;

use App\Filament\Resources\Interviewers\InterviewerResource;
use App\Models\AuditLog;
use App\Models\Interviewer;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use App\Services\InterviewerImportService;
use App\Services\NotificationDispatchService;
use App\Services\Tenancy\TenantUnavailable;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 8.9 (P89-PERF-024, PF-88-04): an interviewer spreadsheet is imported on the `documents` queue,
 * not inside the upload request. The requester is re-checked when the job runs (access and the
 * permission to add interviewers); the import runs on their behalf, the uploaded file is deleted, and
 * the summary the page used to show arrives as an in-app alert.
 */
class ImportInterviewersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $storedPath, public readonly int $userId)
    {
        $this->onQueue('documents');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30];
    }

    public function handle(InterviewerImportService $imports, NotificationDispatchService $alerts): void
    {
        $requester = User::query()->find($this->userId);

        try {
            if ($requester === null || ! app(StaffAccessService::class)->permits($requester) || ! $requester->can('create', Interviewer::class)) {
                return;
            }

            try {
                $result = AuditLog::asActor('queue', $requester->id, fn (): array => $imports->import(Storage::disk('local')->path($this->storedPath), $requester));
            } catch (DomainException $exception) {
                $alerts->alert($requester, 'Interviewers', 'Interviewer import failed', $exception->getMessage(), 'danger', InterviewerResource::getUrl('index'));

                return;
            }

            $skipped = collect($result['skipped']);
            $inactive = collect($result['inactive_not_reactivated']);

            $alerts->alert(
                $requester,
                'Interviewers',
                "{$result['added']} interviewer(s) added",
                collect([
                    $result['already_listed'] > 0 ? "{$result['already_listed']} already on the list." : null,
                    $inactive->isNotEmpty() ? "{$inactive->count()} deactivated, not reactivated: ".$inactive->take(10)->implode('; ') : null,
                    $skipped->isNotEmpty() ? "{$skipped->count()} skipped: ".$skipped->take(10)->implode('; ') : null,
                ])->filter()->implode(' ') ?: 'Import finished.',
                $skipped->isNotEmpty() || $inactive->isNotEmpty() ? 'warning' : 'success',
                InterviewerResource::getUrl('index'),
            );
        } finally {
            Storage::disk('local')->delete($this->storedPath);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // SaaS-7 (S7-02): refused only because the tenant is paused — leave the work as it is, so
        // PausedTenantWork can run it again when the tenant is usable (D-S3-15).
        if ($exception instanceof TenantUnavailable) {
            return;
        }

        Storage::disk('local')->delete($this->storedPath);

        if (($requester = User::query()->find($this->userId)) !== null) {
            app(NotificationDispatchService::class)->alert($requester, 'Interviewers', 'Interviewer import failed', 'The spreadsheet could not be imported. Try again, or check the file.', 'danger', InterviewerResource::getUrl('index'));
        }
    }
}
