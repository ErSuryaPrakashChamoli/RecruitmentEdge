<?php

namespace App\Services\Distribution;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PreferenceStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Events\CandidateAppliedOnline;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Services\CandidateDuplicateDetector;
use App\Services\CandidateIdentityNormalizer;
use App\Services\CandidateTimelineService;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\NotificationDispatchService;
use App\Services\SequenceCodeGenerator;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantStorage;
use Closure;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a career-site application into the existing records (Phase 5): Candidate Master →
 * CandidateApplication → the requisition's pipeline. There is no separate applicant database.
 *
 * Phase 8.8 containment (SEC-88-01 / SEC-88-09): a contact match is not proof of identity. The
 * public form is anonymous, so a submission whose email or mobile strongly matches an existing
 * candidate (the same rule under which staff need a written justification to create a duplicate) is
 * HELD: nothing is written to that candidate — no application, no file, no consent change, no
 * timeline entry — the uploaded file is not stored, the requisition's recruiter is alerted to follow
 * up through the candidate's known contact details, and an audit row records the hold with ids only.
 * Weaker matches still create a new candidate that CandidateObserver logs for HR review. Every
 * outcome returns the same neutral result, so the response never reveals whether the contact
 * details belong to an existing candidate or application.
 */
class CareerApplicationService
{
    /**
     * utm_source values mapped to the seeded candidate source codes (Phase 8.6 D8.6-007: by code,
     * never by name — a rename in Administration must not change attribution).
     *
     * @var array<string, string>
     */
    public const array SOURCE_CODES = [
        'naukri' => 'SRC-001',
        'indeed' => 'SRC-002',
        'linkedin' => 'SRC-003',
        'apna' => 'SRC-004',
        'workindia' => 'SRC-005',
        'whatsapp' => 'SRC-009',
        'facebook' => 'SRC-010',
        'instagram' => 'SRC-011',
    ];

    /**
     * The submission locks this instance holds (oneAtATime), by contact-details hash.
     *
     * @var array<string, true>
     */
    private array $holding = [];

    public function __construct(
        private readonly CandidateDuplicateDetector $duplicates,
        private readonly SequenceCodeGenerator $codes,
        private readonly CandidateTimelineService $timeline,
        private readonly CommunicationPreferenceService $preferences,
        private readonly NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  array{full_name: string, email: string, mobile: string, current_city?: string|null, total_experience?: float|string|null, current_company?: string|null, consent_email?: bool, consent_whatsapp?: bool}  $data
     * @param  array{channel?: string|null, source?: string|null, campaign_id?: int|null, via?: string|null}  $attribution  via: how it arrived, in words (SaaS-6: "the API", "an inbound webhook"); default "the career site"
     * @return array{outcome: 'received'|'held', application: CandidateApplication|null}
     */
    public function apply(JobPosting $posting, array $data, ?UploadedFile $resume = null, array $attribution = []): array
    {
        $posting->loadMissing('requisition.recruiters');

        if (! $posting->isLive()) {
            throw new DomainException('This position is no longer accepting applications.');
        }

        $requisition = $posting->requisition;
        $recruiterId = $requisition->recruiters->first()?->id ?? $requisition->manager_id ?? $requisition->created_by;

        if ($recruiterId === null) {
            throw new DomainException('This position is not accepting online applications yet.');
        }

        return $this->oneAtATime($data, $posting->id, fn (): array => $this->applyOnce($posting, $data, $resume, $attribution, $recruiterId));
    }

    /**
     * Phase 8.9 (P89-DQ-011): submissions with the same contact details are decided one at a time,
     * so a double submit finds the candidate the first one created and is held like any match.
     * One still waiting after the wait gets the same neutral response (SEC-88-09).
     *
     * SaaS-7 (S7-03): a caller that decides inside its own transaction (the API and inbound-webhook
     * intake) takes this before opening it — so it waits holding no row lock, its transaction reads
     * a snapshot taken after the previous submission committed, and the lock is released only after
     * its own commit. apply() inside the callback does not take it again.
     *
     * @template TResult
     *
     * @param  array<string, mixed>  $data
     * @param  Closure(): TResult  $decide
     * @return TResult|array{outcome: 'held', application: null}
     */
    public function oneAtATime(array $data, int $postingId, Closure $decide): mixed
    {
        $identity = sha1(CandidateIdentityNormalizer::email($data['email'] ?? null).'|'.CandidateIdentityNormalizer::mobile($data['mobile'] ?? null));

        if (isset($this->holding[$identity])) {
            return $decide();
        }

        $this->holding[$identity] = true;

        try {
            return Cache::lock(TenantCache::key('career-apply:').$identity, 60)->block(10, $decide);
        } catch (LockTimeoutException) {
            Log::info('careers.submission_in_flight', ['job_posting_id' => $postingId]);

            return ['outcome' => 'held', 'application' => null];
        } finally {
            unset($this->holding[$identity]);
        }
    }

    /**
     * @param  array{full_name: string, email: string, mobile: string, current_city?: string|null, total_experience?: float|string|null, current_company?: string|null, consent_email?: bool, consent_whatsapp?: bool}  $data
     * @param  array{channel?: string|null, source?: string|null, campaign_id?: int|null}  $attribution
     * @return array{outcome: 'received'|'held', application: CandidateApplication|null}
     */
    private function applyOnce(JobPosting $posting, array $data, ?UploadedFile $resume, array $attribution, int $recruiterId): array
    {
        $requisition = $posting->requisition;

        // SEC-88-01: an anonymous submission never acts on an existing candidate it merely matches.
        if (($matched = $this->matchingCandidate($data)) !== null) {
            $this->hold($posting, $matched, $recruiterId);

            return ['outcome' => 'held', 'application' => null];
        }

        return DB::transaction(function () use ($posting, $data, $resume, $attribution, $requisition, $recruiterId): array {
            $candidate = $this->createCandidate($data, $attribution);

            $application = CandidateApplication::query()->create([
                'application_code' => $this->codes->next('APP'),
                'candidate_id' => $candidate->id,
                'requisition_id' => $requisition->id,
                'recruiter_id' => $recruiterId,
                'current_stage' => CandidateStage::Sourced,
                'application_date' => now()->toDateString(),
                'status' => ApplicationStatus::Active,
                'origin_channel' => $attribution['channel'] ?? 'career_site',
                'job_posting_id' => $posting->id,
                'remarks' => 'Applied online via '.($attribution['via'] ?? 'the career site'),
            ]);

            if (($attribution['campaign_id'] ?? null) !== null) {
                $application->forceFill(['campaign_id' => $attribution['campaign_id']])->save();
            }

            if ($resume !== null) {
                CandidateDocument::query()->create([
                    'candidate_id' => $candidate->id,
                    'document_type' => DocumentType::Resume,
                    'file_path' => $resume->store(TenantStorage::path("candidate-documents/{$candidate->id}"), 'local'),
                    'status' => DocumentStatus::Submitted,
                    'remarks' => "Uploaded with online application {$application->application_code}",
                ]);
            }

            if ($data['consent_email'] ?? false) {
                $this->preferences->set($candidate, CommunicationChannel::Email, PreferenceStatus::Allowed, self::consentSource($attribution), reason: 'Consented when applying online');
            }

            if ($data['consent_whatsapp'] ?? false) {
                $this->preferences->set($candidate, CommunicationChannel::WhatsApp, PreferenceStatus::Allowed, self::consentSource($attribution), reason: 'Consented when applying online');
            }

            $this->timeline->record(
                $candidate,
                TimelineEventType::SystemEvent,
                "Applied online for {$posting->title}",
                'Source: '.($attribution['source'] ?? 'career site'),
                TimelineSource::CandidatePortal,
                TimelineVisibility::Candidate,
                related: ['application' => $application, 'subject' => $posting],
                metadata: array_filter(['channel' => $attribution['channel'] ?? 'career_site', 'utm_source' => $attribution['source'] ?? null, 'campaign_id' => $attribution['campaign_id'] ?? null]),
            );

            CandidateAppliedOnline::dispatch($application);

            return ['outcome' => 'received', 'application' => $application];
        });
    }

    /**
     * The existing candidate the submitted contact details strongly match, if any. Used only to
     * decide to hold the submission — never to act on that candidate.
     *
     * @param  array<string, mixed>  $data
     */
    private function matchingCandidate(array $data): ?Candidate
    {
        return $this->duplicates->strongMatches($data)->first()?->candidate;
    }

    /**
     * Records the held submission without touching the matched candidate: an audit row on the
     * posting (ids only — no submitted email, mobile or consent) and one alert per candidate,
     * posting and day to the requisition's recruiter.
     */
    private function hold(JobPosting $posting, Candidate $matched, int $recruiterId): void
    {
        AuditLog::record($posting, 'career_application_held', null, [
            'reason' => 'contact_details_match_existing_candidate',
            'matched_candidate_id' => $matched->id,
        ]);

        $this->notifications->alert(
            Employee::query()->find($recruiterId)?->user,
            'Recruitment',
            'Online application held for review',
            "A career-site application for {$posting->title} used contact details that belong to existing candidate {$matched->candidate_code}. It was not added to their record. Confirm with the candidate through their known contact details before adding an application.",
            'warning',
            null,
            "career-application-held:{$matched->id}:{$posting->id}:".now()->toDateString(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attribution
     */
    private function createCandidate(array $data, array $attribution): Candidate
    {
        // An inactive or archived mapped source falls back to Website, which can never be deactivated.
        $code = self::SOURCE_CODES[strtolower((string) ($attribution['source'] ?? ''))] ?? CandidateSource::CODE_WEBSITE;
        $source = CandidateSource::activeByCode($code)
            ?? CandidateSource::activeByCode(CandidateSource::CODE_WEBSITE)
            ?? CandidateSource::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        return Candidate::query()->create([
            ...array_intersect_key($data, array_flip(['full_name', 'email', 'mobile', 'current_city', 'total_experience', 'current_company'])),
            'candidate_code' => $this->codes->next('CAND'),
            'source_id' => $source->id,
            'source_details' => (($attribution['channel'] ?? 'career_site') === 'career_site' ? 'Career site application' : 'Online application via '.($attribution['via'] ?? $attribution['channel'])).(filled($attribution['source'] ?? null) ? ' (utm_source='.$attribution['source'].')' : ''),
        ]);
    }

    /**
     * Where consent was given, as recorded on the preference.
     *
     * @param  array<string, mixed>  $attribution
     */
    private static function consentSource(array $attribution): string
    {
        $channel = (string) ($attribution['channel'] ?? 'career_site');

        return $channel === 'career_site' ? 'career_site_application' : mb_substr($channel.'_application', 0, 40);
    }
}
