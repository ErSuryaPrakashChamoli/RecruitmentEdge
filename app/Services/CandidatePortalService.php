<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\InterviewStatus;
use App\Enums\PreferenceStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Events\CandidatePortalDocumentUploaded;
use App\Events\CandidatePortalProfileUpdated;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Mail\CandidatePortalLink;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateDocument;
use App\Models\CandidatePortalAccount;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewSchedulingInvitation;
use App\Models\InterviewSlotBooking;
use App\Services\Communication\CommunicationPreferenceService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Candidate portal business logic (Phase 4). Every read and write is resolved through the
 * signed-in account's own candidate — never an identifier taken from the request — so candidate
 * A can never reach candidate B's data (lookups for someone else's record simply 404).
 *
 * What a candidate may see is decided here, not in views: candidate-facing stage labels only,
 * never recruiter remarks, rejection reasons, feedback, scores, AI output or internal salary data.
 */
class CandidatePortalService
{
    /**
     * Profile fields a candidate may change themselves. Identity fields (name, mobile, email) stay
     * with the recruiter, since they drive duplicate detection and communication.
     *
     * @var array<int, string>
     */
    public const array EDITABLE_PROFILE_FIELDS = [
        'alternate_mobile',
        'current_city',
        'location',
        'current_company',
        'current_designation',
        'notice_period_days',
    ];

    /**
     * Document types a candidate may upload themselves.
     *
     * @var array<int, DocumentType>
     */
    public const array UPLOADABLE_DOCUMENT_TYPES = [
        DocumentType::Resume,
        DocumentType::IdProof,
        DocumentType::AddressProof,
        DocumentType::EducationCertificate,
        DocumentType::ExperienceLetter,
        DocumentType::RelievingLetter,
        DocumentType::SalarySlip,
        DocumentType::PhotoId,
        DocumentType::Other,
    ];

    public const int LINK_VALID_HOURS = 48;

    public function __construct(
        private readonly CandidateTimelineService $timeline,
        private readonly InterviewService $interviews,
        private readonly NotificationDispatchService $notifications,
        private readonly CommunicationPreferenceService $preferences,
    ) {}

    /**
     * Creates (or re-activates) the candidate's portal account and emails a set-password link to
     * the candidate. Phase 8.8 (D8.8-037, SEC-88-04): the link is never returned, so staff can never
     * see or use it — a password set through it is recorded as the candidate (D8.8-001), so only the
     * candidate may ever hold it.
     */
    public function invite(Candidate $candidate, ?Employee $actor = null, ?string $email = null): void
    {
        $email = strtolower(trim((string) ($email ?? $candidate->email)));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('The candidate needs a valid email address to get portal access.');
        }

        $account = DB::transaction(function () use ($candidate, $actor, $email): CandidatePortalAccount {
            if (CandidatePortalAccount::query()->where('email', $email)->where('candidate_id', '!=', $candidate->id)->exists()) {
                throw new DomainException('That email address is already used by another candidate\'s portal account.');
            }

            $account = CandidatePortalAccount::query()->firstOrNew(['candidate_id' => $candidate->id]);
            $isNew = ! $account->exists;
            $account->fill(['email' => $email]);
            $account->forceFill(['is_active' => true, 'invited_by' => $actor?->id, 'invited_at' => now()])->save();

            AuditLog::record($account, $isNew ? 'portal_invited' : 'portal_reinvited', null, ['email' => $email]);

            $this->timeline->record(
                $candidate,
                TimelineEventType::PortalAccess,
                'Invited to the candidate portal',
                null,
                TimelineSource::Recruiter,
                actor: $actor,
                related: ['subject' => $account],
            );

            return $account;
        });

        Mail::to($account->email)->send(new CandidatePortalLink($candidate->full_name, $this->passwordLink($account), isInvitation: true));
    }

    public function deactivate(CandidatePortalAccount $account, ?Employee $actor = null): void
    {
        $account->forceFill(['is_active' => false, 'remember_token' => null])->save();

        AuditLog::record($account, 'portal_deactivated', ['is_active' => true], ['is_active' => false]);

        $this->timeline->record($account->candidate_id, TimelineEventType::PortalAccess, 'Candidate portal access revoked', source: TimelineSource::Recruiter, actor: $actor);
    }

    /**
     * A temporary signed link to set (or reset) the password. It carries a fingerprint of the
     * current password hash, so it stops working the moment the password changes.
     */
    public function passwordLink(CandidatePortalAccount $account): string
    {
        return URL::temporarySignedRoute('portal.password.edit', now()->addHours(self::LINK_VALID_HOURS), [
            'account' => $account->public_id,
            'k' => $this->passwordFingerprint($account),
        ]);
    }

    public function passwordFingerprint(CandidatePortalAccount $account): string
    {
        return substr(hash_hmac('sha256', ($account->getRawOriginal('password') ?? 'unset').'|'.$account->public_id, (string) config('app.key')), 0, 32);
    }

    /**
     * Emails a fresh password link when an active account exists — callers must respond the same
     * way either way, so the form never reveals which emails have accounts.
     */
    public function sendPasswordLink(string $email): void
    {
        $account = CandidatePortalAccount::query()->where('email', strtolower(trim($email)))->where('is_active', true)->first();

        if ($account === null) {
            return;
        }

        Mail::to($account->email)->send(new CandidatePortalLink($account->candidate->full_name, $this->passwordLink($account), isInvitation: false));
    }

    /**
     * Phase 8.8 (D8.8-001): a new password ends every other candidate session — their stored
     * fingerprint no longer matches (EnsureCandidateSessionIsCurrent) and the remember-me token
     * is replaced, so no "remember me" cookie from another device signs back in. The caller signs
     * the current request in again and stores the new fingerprint. Staff sessions are unaffected.
     */
    public function setPassword(CandidatePortalAccount $account, string $password): void
    {
        $account->forceFill(['password' => $password, 'password_set_at' => now(), 'remember_token' => Str::random(60)])->save();

        AuditLog::asCandidate($account, fn () => AuditLog::record($account, 'portal_password_set', null, ['password_set_at' => now()->toIso8601String(), 'other_sessions_ended' => true]));
    }

    /**
     * The value a candidate session must carry to stay signed in: tied to the account's current
     * password hash, so it changes when the password changes. Never shown or sent anywhere.
     */
    public function sessionFingerprint(CandidatePortalAccount $account): string
    {
        return hash_hmac('sha256', 'candidate-session|'.($account->getRawOriginal('password') ?? 'unset').'|'.$account->public_id, (string) config('app.key'));
    }

    public function recordLogin(CandidatePortalAccount $account): void
    {
        $account->forceFill(['last_login_at' => now()])->save();

        AuditLog::asCandidate($account, fn () => AuditLog::record($account, 'portal_login', null, ['outcome' => 'succeeded']));
    }

    /**
     * @return Collection<int, CandidateApplication>
     */
    public function applicationsFor(CandidatePortalAccount $account): Collection
    {
        return CandidateApplication::query()
            ->where('candidate_id', $account->candidate_id)
            ->with(['requisition.designation', 'requisition.location', 'pipelineStage', 'interviews' => fn ($q) => $q->orderBy('scheduled_at')])
            ->latest('created_at')
            ->get();
    }

    /**
     * The account's own application by its reference, or a 404 — never someone else's.
     */
    public function findApplication(CandidatePortalAccount $account, string $applicationCode): CandidateApplication
    {
        return CandidateApplication::query()
            ->where('candidate_id', $account->candidate_id)
            ->where('application_code', $applicationCode)
            ->with(['requisition.designation', 'requisition.location', 'pipelineStage', 'interviews' => fn ($q) => $q->orderBy('scheduled_at')])
            ->firstOrFail();
    }

    public function findInterview(CandidateApplication $application, int $roundNumber): Interview
    {
        return $application->interviews()->where('round_number', $roundNumber)->latest('id')->firstOrFail();
    }

    /**
     * A candidate-safe status: never the internal reason for a rejection or dropout.
     */
    public function statusLabel(CandidateApplication $application): string
    {
        return match ($application->status) {
            ApplicationStatus::Active => 'In progress',
            ApplicationStatus::OnHold => 'On hold',
            ApplicationStatus::Rejected => 'Not progressing',
            ApplicationStatus::Dropout => 'Withdrawn',
        };
    }

    /**
     * The candidate-facing name of the application's current stage; stages hidden from candidates
     * read as "In review".
     */
    public function stageLabel(CandidateApplication $application): string
    {
        $stage = $application->pipelineStage;

        if ($stage === null) {
            return $application->current_stage->label();
        }

        return $stage->candidate_visible ? $stage->candidateFacingLabel() : 'In review';
    }

    /**
     * @return Collection<int, InterviewSchedulingInvitation>
     */
    public function openInvitationsFor(CandidatePortalAccount $account): Collection
    {
        return InterviewSchedulingInvitation::query()
            ->whereHas('candidateApplication', fn ($q) => $q->where('candidate_id', $account->candidate_id)->where('status', ApplicationStatus::Active))
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('candidateApplication.requisition.designation')
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, InterviewSlotBooking>
     */
    public function activeBookingsFor(CandidatePortalAccount $account): Collection
    {
        return InterviewSlotBooking::query()
            ->where('candidate_id', $account->candidate_id)
            ->where('status', 'booked')
            ->with(['slot', 'candidateApplication'])
            ->get();
    }

    public function confirmInterview(CandidatePortalAccount $account, Interview $interview): Interview
    {
        $this->ensureOwnInterview($account, $interview);

        return DB::transaction(function () use ($account, $interview): Interview {
            $this->interviews->confirm($interview);

            $this->timeline->record(
                $account->candidate_id,
                TimelineEventType::InterviewConfirmed,
                'You confirmed your interview on '.$interview->scheduled_at->format('d M Y, h:i A'),
                source: TimelineSource::CandidatePortal,
                visibility: TimelineVisibility::Candidate,
                actor: $account,
                related: ['interview' => $interview],
            );

            return $interview;
        });
    }

    /**
     * Records a candidate's request for a new interview time and alerts the recruiter. Self-booked
     * interviews can instead be moved directly (InterviewSchedulingService::reschedule()).
     */
    public function requestReschedule(CandidatePortalAccount $account, Interview $interview, string $reason): void
    {
        $this->ensureOwnInterview($account, $interview);

        if (! in_array($interview->status, [...InterviewStatus::unconfirmed(), InterviewStatus::Confirmed], true)) {
            throw new DomainException('This interview can no longer be rescheduled.');
        }

        $this->timeline->record(
            $account->candidate_id,
            TimelineEventType::RescheduleRequested,
            'You asked to reschedule your interview on '.$interview->scheduled_at->format('d M Y, h:i A'),
            $reason,
            TimelineSource::CandidatePortal,
            TimelineVisibility::Candidate,
            $account,
            ['interview' => $interview],
        );

        $application = $interview->candidateApplication;

        $this->notifications->alert(
            $application->recruiter?->user,
            'Interviews',
            'Candidate requested a new interview time',
            "{$application->candidate->full_name} asked to reschedule interview round {$interview->round_number}: {$reason}",
            'warning',
            CandidateApplicationResource::getUrl('view', ['record' => $application]),
            "reschedule-request-{$interview->id}-".now()->toDateString(),
        );
    }

    /**
     * Updates only the whitelisted profile fields and communication preferences.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string> the fields that actually changed
     */
    public function updateProfile(CandidatePortalAccount $account, array $data): array
    {
        $candidate = $account->candidate;

        $changed = DB::transaction(function () use ($account, $candidate, $data): array {
            $candidate->fill(array_intersect_key($data, array_flip(self::EDITABLE_PROFILE_FIELDS)));
            $changed = array_keys($candidate->getDirty());
            $candidate->save();

            if (array_key_exists('communication_preferences', $data)) {
                // Phase 5: preferences live in candidate_communication_preferences (single source,
                // audited per change) — the portal is just one more place to set them.
                foreach (CandidatePortalAccount::COMMUNICATION_CHANNELS as $channel) {
                    $status = (bool) ($data['communication_preferences'][$channel] ?? false) ? PreferenceStatus::Allowed : PreferenceStatus::OptedOut;
                    $channelEnum = CommunicationChannel::from($channel);

                    if ($this->preferences->statusFor($candidate, $channelEnum) !== $status) {
                        $this->preferences->set($candidate, $channelEnum, $status, 'candidate_portal', $account);
                        $changed[] = "{$channel} preference";
                    }
                }
            }

            if ($changed !== []) {
                $this->timeline->record(
                    $candidate,
                    TimelineEventType::ProfileUpdated,
                    'You updated your profile',
                    'Changed: '.implode(', ', array_map(fn (string $field) => str_replace('_', ' ', $field), $changed)),
                    TimelineSource::CandidatePortal,
                    TimelineVisibility::Candidate,
                    $account,
                );
            }

            return $changed;
        });

        if ($changed !== []) {
            CandidatePortalProfileUpdated::dispatch($account, $changed);
        }

        return $changed;
    }

    /**
     * Stores an uploaded document privately (local disk, per-candidate folder, random file name)
     * and adds it to the candidate's documents as Submitted for recruiter verification. File type
     * and size are validated by the request before this is reached.
     */
    public function uploadDocument(CandidatePortalAccount $account, UploadedFile $file, DocumentType $type): CandidateDocument
    {
        if (! in_array($type, self::UPLOADABLE_DOCUMENT_TYPES, true)) {
            throw new DomainException('That document type cannot be uploaded from the portal.');
        }

        $path = $file->store("candidate-documents/{$account->candidate_id}", 'local');

        $document = DB::transaction(function () use ($account, $type, $path): CandidateDocument {
            $document = CandidateDocument::query()->create([
                'candidate_id' => $account->candidate_id,
                'document_type' => $type,
                'file_path' => $path,
                'status' => DocumentStatus::Submitted,
                'remarks' => 'Uploaded by the candidate via the portal',
            ]);

            AuditLog::record($document, 'portal_uploaded', null, ['document_type' => $type->value]);

            $this->timeline->record(
                $account->candidate_id,
                TimelineEventType::DocumentUploaded,
                "You uploaded a document: {$type->label()}",
                source: TimelineSource::CandidatePortal,
                visibility: TimelineVisibility::Candidate,
                actor: $account,
                related: ['subject' => $document],
            );

            return $document;
        });

        CandidatePortalDocumentUploaded::dispatch($account, $document);

        return $document;
    }

    /**
     * @return Collection<int, CandidateDocument>
     */
    public function documentsFor(CandidatePortalAccount $account): Collection
    {
        return CandidateDocument::query()
            ->where('candidate_id', $account->candidate_id)
            ->latest()
            ->get(['id', 'document_type', 'status', 'created_at']);
    }

    /**
     * Messages the candidate was actually sent (Phase 5): candidate-visible and handed to a
     * provider — never blocked, queued-but-unsent or internal records.
     *
     * @return Collection<int, CandidateCommunication>
     */
    public function messagesFor(CandidatePortalAccount $account, int $limit = 10): Collection
    {
        return CandidateCommunication::query()
            ->where('candidate_id', $account->candidate_id)
            ->where('candidate_visible', true)
            ->whereIn('status', [CommunicationStatus::Sent, CommunicationStatus::Delivered, CommunicationStatus::Read])
            ->latest('sent_at')
            ->limit($limit)
            ->get(['id', 'channel', 'subject', 'body', 'sent_at']);
    }

    private function ensureOwnInterview(CandidatePortalAccount $account, Interview $interview): void
    {
        if ($interview->candidateApplication?->candidate_id !== $account->candidate_id) {
            abort(404);
        }
    }
}
