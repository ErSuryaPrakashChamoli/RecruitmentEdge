<?php

namespace App\Services\Integrations\Handlers;

use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Services\Api\ApplicantIntakeService;
use App\Services\Integrations\Contracts\InboundWebhookHandler;
use Illuminate\Support\Facades\Validator;

/**
 * SaaS-6: an external source (a job board, a sourcing tool, the tenant's own site) submits an
 * applicant to a live job posting — the same intake as the API and the career site.
 *
 * Payload: {"id": "<sender event id>", "type": "application.submitted",
 *           "data": {"job_posting_id": <id>, "applicant": {full_name, email, mobile, …, privacy_consent}}}
 */
final class ApplicationsSubmitHandler implements InboundWebhookHandler
{
    public const string KEY = 'applications.submit';

    public const string EVENT_TYPE = 'application.submitted';

    public function __construct(private readonly ApplicantIntakeService $intake) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Submit applicants to live job postings';
    }

    public function handle(IntegrationConnection $connection, InboundWebhookEvent $event, array $payload): array
    {
        if (($payload['type'] ?? null) !== self::EVENT_TYPE) {
            throw new InvalidPayload('Only "'.self::EVENT_TYPE.'" events are accepted by this connection.');
        }

        $validator = Validator::make((array) ($payload['data'] ?? []), [
            'job_posting_id' => ['required', 'integer', 'min:1'],
            'applicant' => ['required', 'array'],
            ...collect(ApplicantIntakeService::rules())->mapWithKeys(fn (array $rules, string $field): array => ['applicant.'.$field => $rules])->all(),
        ]);

        if ($validator->fails()) {
            throw new InvalidPayload('Invalid applicant: '.implode(', ', array_keys($validator->errors()->toArray())));
        }

        $data = $validator->validated();
        $result = $this->intake->viaConnection($connection, (int) $data['job_posting_id'], (array) $data['applicant']);

        return ['outcome' => $result['outcome'], 'application_id' => $result['application']?->getKey()];
    }
}
