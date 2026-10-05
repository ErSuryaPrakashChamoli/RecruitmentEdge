<?php

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\InboundWebhookStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Webhooks\InboundWebhookProcessor;
use App\Services\Webhooks\WebhookSignature;
use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\ApiWorld;

/*
 * SaaS-6 inbound webhooks: verified by signature, stored once per sender event id, acknowledged,
 * then processed on the queue in the connection's tenant — as the connection, through the same
 * applicant intake as the career site and the API.
 */
beforeEach(function (): void {
    $this->world = ApiWorld::build($this->tenant);
    $this->posting = ApiWorld::livePosting($this->tenant);
    $this->source = IntegrationConnection::factory()->inbound()->create(['name' => 'Job board']);
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function inboundSubmission(int $postingId, string $id = 'evt_1', array $data = []): array
{
    return ['id' => $id, 'type' => 'application.submitted', 'data' => ['job_posting_id' => $postingId, 'applicant' => ['full_name' => 'Arjun Rao', 'email' => 'arjun.rao@example.com', 'mobile' => '+91 98860 54321', 'privacy_consent' => true]], ...$data];
}

function hookPost(string $publicKey, array|string $payload, ?string $secret = IntegrationConnectionFactory::SECRET, ?int $at = null, string $contentType = 'application/json'): TestResponse
{
    $body = is_string($payload) ? $payload : (string) json_encode($payload);
    $server = ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_RE_SIGNATURE'] = WebhookSignature::header($body, $at ?? now()->getTimestamp(), [$secret]);
    }

    return test()->call('POST', '/api/v1/hooks/'.$publicKey, [], [], [], $server, $body);
}

test('a signed submission is acknowledged, then becomes an application in the connection\'s tenant, attributed to the connection', function (): void {
    hookPost($this->source->public_key, inboundSubmission($this->posting->id))->assertStatus(202)->assertExactJson(['status' => 'accepted', 'id' => 'evt_1']);

    $application = CandidateApplication::query()->sole();
    $event = InboundWebhookEvent::query()->sole();

    expect($application)->origin_channel->toBe('webhook')->job_posting_id->toBe($this->posting->id)
        ->and($event)->status->toBe(InboundWebhookStatus::Processed)->result->toMatchArray(['outcome' => 'received', 'application_id' => $application->id])
        ->and(AuditLog::query()->where('auditable_type', Candidate::class)->where('action', 'created')->sole())
        ->actor_kind->toBe('integration')->actor_type->toBe($this->source->getMorphClass())->actor_id->toBe($this->source->id)->user_id->toBeNull()
        ->and((string) DB::table('inbound_webhook_events')->value('payload_encrypted'))->not->toContain('arjun.rao');
});

test('the same sender event is stored and processed once', function (): void {
    hookPost($this->source->public_key, inboundSubmission($this->posting->id))->assertStatus(202);
    hookPost($this->source->public_key, inboundSubmission($this->posting->id))->assertOk()->assertExactJson(['status' => 'duplicate', 'id' => 'evt_1']);

    expect(InboundWebhookEvent::query()->count())->toBe(1)
        ->and(CandidateApplication::query()->count())->toBe(1);
});

test('an unsigned, wrongly signed, stale or altered submission is refused and stored nowhere', function (string $case): void {
    $payload = inboundSubmission($this->posting->id);
    $body = (string) json_encode($payload);

    $response = match ($case) {
        'unsigned' => hookPost($this->source->public_key, $payload, secret: null),
        'wrong secret' => hookPost($this->source->public_key, $payload, secret: 'whsec_not-the-secret'),
        'stale' => hookPost($this->source->public_key, $payload, at: now()->subSeconds(301)->getTimestamp()),
        'altered' => test()->call('POST', '/api/v1/hooks/'.$this->source->public_key, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_RE_SIGNATURE' => WebhookSignature::header($body, now()->getTimestamp(), [IntegrationConnectionFactory::SECRET])], str_replace('Arjun', 'Admin', $body)),
    };

    $response->assertUnauthorized()->assertJsonPath('error.code', 'invalid_signature');
    expect(InboundWebhookEvent::query()->count())->toBe(0)
        ->and($this->source->fresh()->last_failure_at)->not->toBeNull();
})->with(['unsigned', 'wrong secret', 'stale', 'altered']);

test('an unknown endpoint, a disabled one, an unentitled or a suspended tenant accepts nothing', function (string $case, int $status): void {
    $key = $this->source->public_key;

    match ($case) {
        'unknown' => $key = 'in_'.str_repeat('a', 32),
        'malformed key' => $key = '../../etc',
        'disabled' => $this->source->forceFill(['status' => ConnectionStatus::Disabled])->save(),
        'unentitled' => app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::IntegrationsWebhooks, false, 'Off'),
        'suspended' => $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save(),
    };

    hookPost($key, inboundSubmission($this->posting->id))->assertStatus($status);
    expect(InboundWebhookEvent::query()->withoutTenancy()->count())->toBe(0);
})->with([
    'unknown' => ['unknown', 404],
    'malformed key' => ['malformed key', 404],
    'disabled' => ['disabled', 403],
    'unentitled' => ['unentitled', 403],
    'suspended' => ['suspended', 403],
]);

test('the body must be small, JSON, and carry an id and a type', function (): void {
    config(['api.limits.inbound_webhook_body_bytes' => 2048]);

    hookPost($this->source->public_key, inboundSubmission($this->posting->id), contentType: 'text/plain')->assertStatus(415);
    hookPost($this->source->public_key, '{"id": ')->assertStatus(400);
    hookPost($this->source->public_key, inboundSubmission($this->posting->id, data: ['padding' => str_repeat('x', 2100)]))->assertStatus(413);
    hookPost($this->source->public_key, ['type' => 'application.submitted'])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_payload');
    hookPost($this->source->public_key, ['id' => "evt\n1", 'type' => 'application.submitted'])->assertUnprocessable();

    expect(InboundWebhookEvent::query()->count())->toBe(0);
});

test('content that can never be processed is ignored, with a reason and no applicant data', function (array $payload, string $reason): void {
    hookPost($this->source->public_key, $payload)->assertStatus(202);

    expect(InboundWebhookEvent::query()->sole())->status->toBe(InboundWebhookStatus::Ignored)->last_error->toContain($reason)->last_error->not->toContain('arjun')
        ->and(CandidateApplication::query()->count())->toBe(0);
})->with([
    'another event type' => fn (): array => [inboundSubmission($this->posting->id, data: ['type' => 'candidate.deleted']), 'Only "application.submitted"'],
    'an invalid applicant' => fn (): array => [inboundSubmission($this->posting->id, data: ['data' => ['job_posting_id' => $this->posting->id, 'applicant' => ['full_name' => 'Arjun Rao', 'email' => 'arjun.rao@', 'privacy_consent' => false]]]), 'applicant.email'],
    'an unknown posting' => fn (): array => [inboundSubmission(999999), 'No such job posting'],
]);

test('a submission can only reach postings of the connection\'s own tenant, whatever the payload claims', function (): void {
    $betaPosting = ApiWorld::livePosting($this->world->identity->beta, 'Beta role');

    hookPost($this->source->public_key, inboundSubmission($betaPosting->id, data: ['tenant_id' => $this->world->identity->beta->id]))->assertStatus(202);

    expect(InboundWebhookEvent::query()->sole()->status)->toBe(InboundWebhookStatus::Ignored)
        ->and(CandidateApplication::query()->withoutTenancy()->count())->toBe(0);
});

test('an event refused at processing time fails and can be processed again by an administrator', function (): void {
    Queue::fake();
    hookPost($this->source->public_key, inboundSubmission($this->posting->id))->assertStatus(202);
    $event = InboundWebhookEvent::query()->sole();
    $connections = app(IntegrationConnectionService::class);

    $connections->disable($this->source, $this->world->identity->adminA, 'Paused');
    expect(app(InboundWebhookProcessor::class)->process($event->id))->toBe('failed');

    $connections->enable($this->source->fresh(), $this->world->identity->adminA);
    $connections->reprocessInbound($event->fresh(), $this->world->identity->adminA);

    expect(app(InboundWebhookProcessor::class)->process($event->id))->toBe('processed')
        ->and(app(InboundWebhookProcessor::class)->process($event->id))->toBe('not claimable')
        ->and($event->fresh()->reprocess_count)->toBe(1)
        ->and(CandidateApplication::query()->count())->toBe(1)
        ->and(fn () => $connections->reprocessInbound($event->fresh(), $this->world->identity->adminA))->toThrow(DomainException::class, 'Only a failed or ignored');
});

test('after a rotation the previous secret is accepted until the overlap ends', function (): void {
    $rotated = app(IntegrationConnectionService::class)->rotateSecret($this->source, $this->world->identity->adminA);

    hookPost($this->source->public_key, inboundSubmission($this->posting->id, 'evt_old'))->assertStatus(202);
    hookPost($this->source->public_key, inboundSubmission($this->posting->id, 'evt_new'), secret: $rotated['secret'])->assertStatus(202);

    $this->travel(25)->hours();
    hookPost($this->source->public_key, inboundSubmission($this->posting->id, 'evt_late'))->assertUnauthorized();
});

test('each inbound connection is rate limited', function (): void {
    config(['api.rate_limits.per_inbound_connection' => 2]);
    Queue::fake();

    hookPost($this->source->public_key, inboundSubmission($this->posting->id, 'evt_a'))->assertStatus(202);
    hookPost($this->source->public_key, inboundSubmission($this->posting->id, 'evt_b'))->assertStatus(202);
    hookPost($this->source->public_key, inboundSubmission($this->posting->id, 'evt_c'))->assertStatus(429);
});
