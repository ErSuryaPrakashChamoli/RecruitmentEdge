<?php

namespace App\Listeners;

use App\Enums\ApplicationStatus;
use App\Events\CandidatePortalDocumentUploaded;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\CandidateApplication;
use App\Services\NotificationDispatchService;

/**
 * Alerts the recruiters of the candidate's active applications that a document arrived through
 * the portal and awaits verification. Discovered automatically.
 */
class NotifyRecruitersOfPortalDocument
{
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
