<?php

namespace App\Listeners;

use App\Enums\ApplicationStatus;
use App\Events\CandidatePortalDocumentUploaded;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\CandidateApplication;
use App\Services\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alerts the recruiters of the candidate's active applications that a document arrived through
 * the portal and awaits verification. Discovered automatically.
 *
 * Phase 8.9 (P89-PERF-024, E-14 / PF-88-09): queued on `notifications`, so the candidate's upload
 * never waits while every recruiter is looked up and alerted.
 */
class NotifyRecruitersOfPortalDocument implements ShouldBeEncrypted, ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * Phase 8.7 (D8.7-013): an exhausted listener is recorded — event and ids only, never content.
     */
    public function failed(object $event, Throwable $exception): void
    {
        Log::error('queue.listener_failed', ['listener' => static::class, 'event' => $event::class, 'error' => $exception::class]);
    }

    public function __construct(private readonly NotificationDispatchService $notifications) {}

    public function handle(CandidatePortalDocumentUploaded $event): void
    {
        $candidate = $event->account->candidate;

        CandidateApplication::query()
            ->where('candidate_id', $candidate->id)
            ->where('status', ApplicationStatus::Active)
            ->with('recruiter.user')
            ->get()
            ->map(fn (CandidateApplication $application) => $application->recruiter?->user)
            ->filter()
            ->unique('id')
            ->each(fn ($user) => $this->notifications->alert(
                $user,
                'Candidates',
                'Document uploaded by candidate',
                "{$candidate->full_name} uploaded a {$event->document->document_type->label()} via the candidate portal.",
                'info',
                CandidateResource::getUrl('view', ['record' => $candidate]),
                "portal-document-{$event->document->id}-{$user->id}",
            ));
    }
}
