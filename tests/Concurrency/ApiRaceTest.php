<?php

use App\Enums\ApiScope;
use App\Enums\Entitlement;
use App\Enums\InboundWebhookStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ApiCredential;
use App\Models\ApiIdempotencyKey;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Api\ApiCredentialService;
use App\Services\Api\ApiPrincipal;
use App\Services\Api\ApplicantIntakeService;
use App\Services\Api\IdempotencyService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\Tenancy\TenantContext;
use App\Services\Webhooks\InboundWebhookProcessor;
use App\Services\Webhooks\InboundWebhookReceiver;
use App\Services\Webhooks\WebhookSignature;
use Database\Factories\IntegrationConnectionFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concurrency\Race;
use Tests\Feature\Api\ApiWorld;

/*
 * SaaS-6: the API and webhooks under real two-transaction concurrency (MySQL 8.4, REPEATABLE READ).
 * Each race holds one writer's lock open and shows the contender blocks on exactly that row or key,
 * then decides on the committed outcome. Each test has its own tenant (some races close it).
 * vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');
    Storage::fake('public');
    Mail::fake();
    Queue::fake();

    $this->target = Tenant::factory()->create(['slug' => 'api-'.Str::lower(Str::random(10))]);
    Race::pinPlan($this->target, 'legacy');
    app(EntitlementOverrideService::class)->set($this->target->fresh(), Entitlement::ApiAccess, true, 'Race');
    app(EntitlementOverrideService::class)->set($this->target->fresh(), Entitlement::IntegrationsWebhooks, true, 'Race');
    TenantContext::current()->setTenant($this->target->fresh());

    (new RolePermissionSeeder)->run();
    $this->owner = User::factory()->create(['email' => 'owner.'.Str::random(8).'@api-race.test'])->assignRole('chro');
    $issued = app(ApiCredentialService::class)->issue($this->owner, 'Race', ApiScope::cases(), 30);
    $this->credential = $issued['credential'];
    $this->posting = ApiWorld::livePosting($this->target);
    $this->source = IntegrationConnection::factory()->inbound()->create();
});

afterEach(function (): void {
    TenantContext::current()->setTenant(Tenant::query()->where('slug', 'concurrency')->firstOrFail());
});

/**
 * Which tables the contender is waiting on (performance_schema), read while it is blocked.
 */
function apiRaceWaitingOn(): array
{
    return collect(DB::select("SELECT DISTINCT OBJECT_NAME AS object FROM performance_schema.data_locks WHERE LOCK_STATUS = 'WAITING' AND OBJECT_SCHEMA = DATABASE()"))->pluck('object')->all();
}

/**
 * @return array<string, mixed>
 */
function apiRaceApplicant(string $email = 'race.applicant@example.com'): array
{
    return ['full_name' => 'Race Applicant', 'email' => $email, 'mobile' => '+91 98450 00000', 'privacy_consent' => true];
}

function apiRacePrincipal(object $test): ApiPrincipal
{
    return new ApiPrincipal(ApiCredential::query()->findOrFail($test->credential->id), User::query()->findOrFail($test->owner->id), Tenant::query()->findOrFail($test->target->id));
}

function apiRaceHook(IntegrationConnection $source, string $externalId, int $postingId): Request
{
    $body = (string) json_encode(['id' => $externalId, 'type' => 'application.submitted', 'data' => ['job_posting_id' => $postingId, 'applicant' => apiRaceApplicant()]]);

    return Request::create('/api/v1/hooks/'.$source->public_key, 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_RE_SIGNATURE' => WebhookSignature::header($body, now()->getTimestamp(), [IntegrationConnectionFactory::SECRET])], content: $body);
}

test('identical mutations: the second request with the same Idempotency-Key waits on the first one\'s key, then is told it is in progress', function (): void {
    $begin = fn () => app(IdempotencyService::class)->begin(ApiCredential::query()->findOrFail($this->credential->id), 'order-1', 'POST', 'api/v1/job-postings/1/applications', '{"a":1}');

    $race = Race::run(holder: $begin, contender: $begin, whileBlocked: fn () => apiRaceWaitingOn());

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['api_idempotency_keys'])
        ->and($race['message'])->toContain('still being processed')
        ->and(ApiIdempotencyKey::query()->count())->toBe(1);
});

test('idempotency key reuse: a concurrent request with the same key and another body is refused once the first commits', function (): void {
    $route = 'api/v1/job-postings/1/applications';

    $race = Race::run(
        holder: fn () => app(IdempotencyService::class)->begin(ApiCredential::query()->findOrFail($this->credential->id), 'order-1', 'POST', $route, '{"a":1}'),
        contender: fn () => app(IdempotencyService::class)->begin(ApiCredential::query()->findOrFail($this->credential->id), 'order-1', 'POST', $route, '{"a":2}'),
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['api_idempotency_keys'])
        ->and($race['message'])->toContain('different request')
        ->and(ApiIdempotencyKey::query()->sole()->request_hash)->toBe(hash('sha256', 'POST '.$route."\n".'{"a":1}'));
});

test('credential creation at the cap: the second issue waits on the tenant row and is refused', function (): void {
    config(['api.credentials.max_active_per_tenant' => 2]);

    $race = Race::run(
        holder: fn () => app(ApiCredentialService::class)->issue($this->owner, 'First', [ApiScope::MasterDataRead], 30),
        contender: fn () => app(ApiCredentialService::class)->issue(User::query()->findOrFail($this->owner->id), 'Second', [ApiScope::MasterDataRead], 30),
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toContain('most active')
        ->and(ApiCredential::query()->whereNull('revoked_at')->count())->toBe(2);
});

test('revoke vs use: an intake started with the credential waits on it and is refused once the revocation commits', function (): void {
    $race = Race::run(
        holder: fn () => app(ApiCredentialService::class)->revoke($this->credential, $this->owner, 'Leaked'),
        contender: fn () => app(ApplicantIntakeService::class)->viaCredential(apiRacePrincipal($this), $this->posting->id, apiRaceApplicant()),
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['api_credentials'])
        ->and($race['message'])->toContain('has been revoked')
        ->and(Candidate::query()->where('email', 'race.applicant@example.com')->exists())->toBeFalse();
});

test('duplicate inbound delivery: the second copy waits on the first one\'s event id and is acknowledged as a duplicate', function (): void {
    $receive = fn () => app(InboundWebhookReceiver::class)->receive(apiRaceHook($this->source, 'evt_dup', $this->posting->id), $this->source->public_key);

    $race = Race::run(
        holder: $receive,
        contender: function () use ($receive): void {
            $response = $receive();

            throw new RuntimeException('answered '.$response->getStatusCode().' '.$response->getData(true)['status']);
        },
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['inbound_webhook_events'])
        ->and($race['message'])->toBe('answered 200 duplicate')
        ->and(InboundWebhookEvent::query()->count())->toBe(1);
});

test('disable vs use: an inbound intake waits on the connection and is refused once it is disabled', function (): void {
    $race = Race::run(
        holder: fn () => app(IntegrationConnectionService::class)->disable($this->source, $this->owner, 'Incident'),
        contender: fn () => app(ApplicantIntakeService::class)->viaConnection(IntegrationConnection::query()->findOrFail($this->source->id), $this->posting->id, apiRaceApplicant()),
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['integration_connections'])
        ->and($race['message'])->toContain('disabled')
        ->and(Candidate::query()->where('email', 'race.applicant@example.com')->exists())->toBeFalse();
});

test('replay vs replay: the second replay waits on the delivery and is refused — one replay, one send', function (): void {
    $endpoint = IntegrationConnection::factory()->create();
    $event = WebhookEvent::query()->create(['event_key' => 'evt_race', 'type' => 'candidate.created', 'subject_type' => 'candidate', 'subject_id' => 1, 'payload' => [], 'occurred_at' => now()]);
    $delivery = WebhookDelivery::query()->create(['webhook_event_id' => $event->id, 'integration_connection_id' => $endpoint->id, 'delivery_key' => 'dlv_race', 'status' => WebhookDeliveryStatus::Failed, 'attempts' => 7]);

    $race = Race::run(
        holder: fn () => app(IntegrationConnectionService::class)->replayDelivery($delivery, $this->owner),
        contender: fn () => app(IntegrationConnectionService::class)->replayDelivery(WebhookDelivery::query()->findOrFail($delivery->id), User::query()->findOrFail($this->owner->id)),
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['webhook_deliveries'])
        ->and($race['message'])->toContain('already queued')
        ->and($delivery->fresh()->replay_count)->toBe(1);
});

test('rotation vs rotation: the second waits on the connection, so the first rotation\'s secret stays valid through the overlap', function (): void {
    $first = null;

    $race = Race::run(
        holder: function () use (&$first): void {
            $first = app(IntegrationConnectionService::class)->rotateSecret($this->source, $this->owner)['secret'];
        },
        contender: fn () => app(IntegrationConnectionService::class)->rotateSecret(IntegrationConnection::query()->findOrFail($this->source->id), User::query()->findOrFail($this->owner->id)),
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    $secrets = $this->source->fresh()->secrets;

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['integration_connections'])
        ->and($race['completed'])->toBeTrue()
        ->and($secrets['previous'])->toBe($first)
        ->and($secrets['current'])->not->toBe($first);
});

test('suspension during a request: an API intake waits on the tenant row and is refused once the suspension commits', function (): void {
    $race = Race::run(
        holder: fn () => app(TenantLifecycleService::class)->suspend($this->target->fresh(), 'Commercial hold'),
        contender: function () {
            // A request authenticated just before the suspension committed (its own tenant, read then).
            TenantContext::current()->setTenant(Tenant::query()->findOrFail($this->target->id));

            return app(ApplicantIntakeService::class)->viaCredential(apiRacePrincipal($this), $this->posting->id, apiRaceApplicant());
        },
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toContain('not active')
        ->and(Candidate::query()->where('email', 'race.applicant@example.com')->exists())->toBeFalse();
});

test('cancellation during an integration job: inbound processing waits on the tenant row and fails without writing', function (): void {
    $event = InboundWebhookEvent::query()->create([
        'integration_connection_id' => $this->source->id, 'external_id' => 'evt_job', 'type' => 'application.submitted', 'status' => InboundWebhookStatus::Received,
        'payload_encrypted' => ['id' => 'evt_job', 'type' => 'application.submitted', 'data' => ['job_posting_id' => $this->posting->id, 'applicant' => apiRaceApplicant()]],
        'payload_hash' => str_repeat('a', 64), 'received_at' => now(),
    ]);

    $race = Race::run(
        holder: fn () => app(TenantLifecycleService::class)->cancel($this->target->fresh(), 'Contract ended'),
        contender: function () use ($event): void {
            // A job that started just before the cancellation committed (its own tenant, read then).
            TenantContext::current()->setTenant(Tenant::query()->findOrFail($this->target->id));
            $outcome = app(InboundWebhookProcessor::class)->process($event->id);

            throw new RuntimeException('processed as '.$outcome);
        },
        whileBlocked: fn () => apiRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toBe('processed as failed')
        ->and($event->fresh()->status)->toBe(InboundWebhookStatus::Failed)
        ->and(CandidateApplication::query()->where('job_posting_id', $this->posting->id)->exists())->toBeFalse();
});
