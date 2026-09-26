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
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidateSource;
use App\Models\JobPosting;
use App\Services\CandidateDuplicateDetector;
use App\Services\CandidateTimelineService;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\SequenceCodeGenerator;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Turns a career-site application into the existing records (Phase 5): Candidate Master →
 * CandidateApplication → the requisition's pipeline. There is no separate applicant database.
 *
 * Duplicates: an exact email or (normalised) mobile match reuses that candidate — the public can't
 * justify an override, and matching on a verified contact detail is safe; weaker matches create a
 * new candidate and CandidateObserver logs them for HR review. A candidate who already applied to
 * the requisition gets their existing application back (no duplicate application). The candidate
 * master record is never modified by a public submission.
 */
class CareerApplicationService
{
    /**
     * utm_source values mapped to existing candidate source names.
     *
     * @var array<string, string>
     */
    public const array SOURCE_NAMES = [
        'linkedin' => 'LinkedIn',
        'naukri' => 'Naukri',
        'indeed' => 'Indeed',
        'apna' => 'Apna',
        'workindia' => 'WorkIndia',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp',
    ];

    public function __construct(
        private readonly CandidateDuplicateDetector $duplicates,
        private readonly SequenceCodeGenerator $codes,
        private readonly CandidateTimelineService $timeline,
        private readonly CommunicationPreferenceService $preferences,
    ) {}

    /**
     * @param  array{full_name: string, email: string, mobile: string, current_city?: string|null, total_experience?: float|string|null, current_company?: string|null, consent_email?: bool, consent_whatsapp?: bool}  $data
     * @param  array{channel?: string|null, source?: string|null, campaign_id?: int|null}  $attribution
     * @return array{application: CandidateApplication, existing: bool}
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

        return DB::transaction(function () use ($posting, $data, $resume, $attribution, $requisition, $recruiterId): array {
            $candidate = $this->matchingCandidate($data) ?? $this->createCandidate($data, $attribution);

            $existing = CandidateApplication::query()->where('candidate_id', $candidate->id)->where('requisition_id', $requisition->id)->first();

            if ($existing !== null) {
                return ['application' => $existing, 'existing' => true];
            }

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
                'remarks' => 'Applied online via the career site',
            ]);

            if (($attribution['campaign_id'] ?? null) !== null) {
                $application->forceFill(['campaign_id' => $attribution['campaign_id']])->save();
            }

            if ($resume !== null) {
                CandidateDocument::query()->create([
                    'candidate_id' => $candidate->id,
                    'document_type' => DocumentType::Resume,
                    'file_path' => $resume->store("candidate-documents/{$candidate->id}", 'local'),
                    'status' => DocumentStatus::Submitted,
                    'remarks' => "Uploaded with online application {$application->application_code}",
                ]);
            }

            if ($data['consent_email'] ?? false) {
                $this->preferences->set($candidate, CommunicationChannel::Email, PreferenceStatus::Allowed, 'career_site_application', reason: 'Consented when applying online');
            }

            if ($data['consent_whatsapp'] ?? false) {
                $this->preferences->set($candidate, CommunicationChannel::WhatsApp, PreferenceStatus::Allowed, 'career_site_application', reason: 'Consented when applying online');
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

            return ['application' => $application, 'existing' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function matchingCandidate(array $data): ?Candidate
    {
        return $this->duplicates->detect($data)
            ->first(fn ($match) => $match->confidence >= 90 && array_intersect($match->matchingFields, ['mobile', 'email']) !== [])
            ?->candidate;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attribution
     */
    private function createCandidate(array $data, array $attribution): Candidate
    {
        $sourceName = self::SOURCE_NAMES[strtolower((string) ($attribution['source'] ?? ''))] ?? 'Website';
        $source = CandidateSource::query()->where('name', $sourceName)->first()
            ?? CandidateSource::query()->where('name', 'Website')->first()
            ?? CandidateSource::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        return Candidate::query()->create([
            ...array_intersect_key($data, array_flip(['full_name', 'email', 'mobile', 'current_city', 'total_experience', 'current_company'])),
            'candidate_code' => $this->codes->next('CAND'),
            'source_id' => $source->id,
            'source_details' => 'Career site application'.(filled($attribution['source'] ?? null) ? ' (utm_source='.$attribution['source'].')' : ''),
        ]);
    }
}
