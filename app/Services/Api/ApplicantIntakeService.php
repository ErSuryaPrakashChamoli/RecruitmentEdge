<?php

namespace App\Services\Api;

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Models\ApiCredential;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\IntegrationConnection;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use App\Models\Tenant;
use App\Services\Distribution\CareerApplicationService;
use App\Services\Entitlements\EntitlementService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SaaS-6: an external system submits an applicant to one of the tenant's live job postings — the
 * only write the API and inbound webhooks offer. It is the career site's own intake
 * (CareerApplicationService): the same duplicate hold, consent, timeline, CandidateAppliedOnline
 * event and automation. Creating an application is not a hiring decision; nothing here moves,
 * selects, rejects or offers.
 *
 * Decided under shared locks on the tenant row and on the credential (or connection) row, so a
 * suspension, deletion, revocation or disablement that commits first is seen, and one that comes
 * later waits for this intake to finish.
 */
class ApplicantIntakeService
{
    public function __construct(
        private readonly CareerApplicationService $applications,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Validation rules of a submitted applicant (the career site's form, without the file).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'mobile' => ['required', 'string', 'regex:/^[0-9+\-\s()]{8,20}$/'],
            'current_city' => ['nullable', 'string', 'max:120'],
            'total_experience' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'current_company' => ['nullable', 'string', 'max:255'],
            'consent_email' => ['nullable', 'boolean'],
            'consent_whatsapp' => ['nullable', 'boolean'],
            'privacy_consent' => ['accepted'],
            'source' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Through the API, as the credential's owner.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{outcome: 'received'|'held', application: CandidateApplication|null}
     */
    public function viaCredential(ApiPrincipal $principal, int $jobPostingId, array $data): array
    {
        if (Gate::forUser($principal->owner)->denies('create', Candidate::class)) {
            throw ApiException::forbidden('forbidden', 'The credential\'s owner may not add candidates.');
        }

        // SaaS-7 (S7-03): the applicant's submission lock first, then the transaction.
        return $this->applications->oneAtATime($data, $jobPostingId, fn (): array => DB::transaction(function () use ($principal, $jobPostingId, $data): array {
            $this->lockUsableTenant($principal->tenant);

            /** @var ApiCredential|null $credential */
            $credential = ApiCredential::query()->whereKey($principal->credential->getKey())->sharedLock()->first();

            if ($credential === null || ! $credential->isUsable()) {
                throw ApiException::inactiveCredential();
            }

            $posting = $this->livePosting($jobPostingId, fn ($requisitions) => $requisitions->visibleTo($principal->owner));

            return $this->applications->apply($posting, $data, null, ['channel' => 'api', 'via' => 'the API', 'source' => $data['source'] ?? null]);
        }));
    }

    /**
     * Through an inbound webhook connection (the connection is the actor).
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{outcome: 'received'|'held', application: CandidateApplication|null}
     */
    public function viaConnection(IntegrationConnection $connection, int $jobPostingId, array $data): array
    {
        return $this->applications->oneAtATime($data, $jobPostingId, fn (): array => DB::transaction(function () use ($connection, $jobPostingId, $data): array {
            $tenant = $this->lockUsableTenant(Tenant::query()->findOrFail($connection->tenant_id));

            /** @var IntegrationConnection|null $locked */
            $locked = IntegrationConnection::query()->whereKey($connection->getKey())->sharedLock()->first();

            if ($locked === null || $locked->status !== ConnectionStatus::Active) {
                throw new DomainException('The connection is disabled.');
            }

            if (! $this->entitlements->allows(Entitlement::IntegrationsWebhooks, $tenant)) {
                throw new DomainException('Webhooks are not included in this organisation\'s plan.');
            }

            $posting = $this->livePosting($jobPostingId, fn ($requisitions) => $requisitions);

            return $this->applications->apply($posting, $data, null, ['channel' => 'webhook', 'via' => 'an inbound webhook', 'source' => $data['source'] ?? null]);
        }));
    }

    private function lockUsableTenant(Tenant $tenant): Tenant
    {
        /** @var Tenant|null $locked */
        $locked = Tenant::query()->whereKey($tenant->getKey())->sharedLock()->first();

        if ($locked === null || ! $locked->isUsable()) {
            throw ApiException::forbidden('tenant_unavailable', 'This organisation\'s account is not active.');
        }

        return $locked;
    }

    /**
     * @param  callable(Builder<RecruitmentRequisition>): mixed  $visible
     */
    private function livePosting(int $jobPostingId, callable $visible): JobPosting
    {
        /** @var JobPosting|null $posting */
        $posting = JobPosting::query()->whereKey($jobPostingId)
            ->whereHas('requisition', fn ($requisitions) => $visible($requisitions))
            ->first();

        if ($posting === null) {
            throw new ApiException('not_found', 'No such job posting.', 404);
        }

        if (! $posting->isLive()) {
            throw new ApiException('posting_closed', 'This job posting is not accepting applications.', 422);
        }

        return $posting;
    }
}
