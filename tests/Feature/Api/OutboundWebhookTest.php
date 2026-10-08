<?php

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\IntegrationConnection;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Integrations\Http\OutboundUrlGuard;
use App\Services\Integrations\Http\UnsafeDestination;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Tenancy\TenantContext;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookSignature;
use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\ApiWorld;
use Tests\Feature\Api\FakeHostResolver;

/*
 * SaaS-6 outbound webhooks: thin, signed events to the tenant's own https endpoints, never to an
 * internal address, retried then failed, replayable, and only while the tenant is entitled.
 */
beforeEach(function (): void {
    $this->world = ApiWorld::build($this->tenant);
    $this->resolver = FakeHostResolver::install(['internal.example.com' => ['10.0.0.5'], 'mixed.example.com' => [FakeHostResolver::PUBLIC_ADDRESS, '169.254.169.254']]);
    $this->connections = app(IntegrationConnectionService::class);
});

function signedWith(Request $request, string $secret): bool
{
    return WebhookSignature::verify($request->header(WebhookDeliveryService::SIGNATURE_HEADER)[0] ?? '', $request->body(), [$secret], 300, now()->getTimestamp());
}

test('the URL guard refuses every non-public or non-https destination', function (string $url): void {
    expect(fn () => app(OutboundUrlGuard::class)->check($url))->toThrow(UnsafeDestination::class);
})->with([
    'plain http' => 'http://hooks.example.com/x',
    'credentials in the URL' => 'https://user:pass@hooks.example.com/x',
    'a port not allowed' => 'https://hooks.example.com:8080/x',
    'loopback' => 'https://127.0.0.1/x',
    'private' => 'https://10.1.2.3/x',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'carrier-grade NAT' => 'https://100.64.0.1/x',
    'IPv6 loopback' => 'https://[::1]/x',
    'IPv4-mapped loopback' => 'https://[::ffff:127.0.0.1]/x',
    'IPv6 unique local' => 'https://[fd00::1]/x',
    'a name resolving privately' => 'https://internal.example.com/x',
    'a name with one private answer' => 'https://mixed.example.com/x',
    'a name that does not resolve' => 'https://nowhere.example.com/x',
    'no host' => 'https:///x',
    'a decimal address' => 'https://2130706433/x',
    'a hex address' => 'https://0x7f000001/x',
    'a shortened address' => 'https://127.1/x',
    'a backslash' => 'https://hooks.example.com\\@127.0.0.1/x',
    'a space in the host' => 'https://hooks.example.com /x',
]);

test('a public https destination is pinned to the address that was checked', function (): void {
    $destination = app(OutboundUrlGuard::class)->check('https://hooks.example.com:8443/recruitment?token=abc');

    expect($destination->pin())->toBe('hooks.example.com:8443:'.FakeHostResolver::PUBLIC_ADDRESS)
        ->and(app(OutboundUrlGuard::class)->check('https://[2606:4700::1111]/x')->pin())->toBe('2606:4700::1111:443:[2606:4700::1111]');
});

test('an endpoint can only be saved with a safe URL; its secret is encrypted and the URL\'s query is kept out of the audit trail', function (): void {
    expect(fn () => $this->connections->createOutbound($this->world->identity->adminA, 'Internal', 'https://internal.example.com/x', [WebhookEventType::CandidateCreated]))->toThrow(UnsafeDestination::class);

    $created = $this->connections->createOutbound($this->world->identity->adminA, 'ATS', 'https://hooks.example.com/in?token=receiver-token', [WebhookEventType::CandidateCreated]);
    $raw = (array) DB::table('integration_connections')->where('id', $created['connection']->id)->first();

    expect($created['secret'])->toStartWith('whsec_')
        ->and($raw['secrets'])->not->toContain($created['secret'])
        ->and(json_encode(AuditLog::query()->get()))->not->toContain($created['secret'])->not->toContain('receiver-token')
        ->and($created['connection']->toArray())->not->toHaveKey('secrets');
});

test('an event goes, signed and thin, to each active endpoint subscribed to it — and to no other tenant', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $subscribed = IntegrationConnection::factory()->create(['config' => ['url' => 'https://hooks.example.com/a', 'events' => ['candidate.created']]]);
    IntegrationConnection::factory()->create(['config' => ['url' => 'https://hooks.example.com/b', 'events' => ['requisition.status_changed']]]);
    IntegrationConnection::factory()->create(['status' => ConnectionStatus::Disabled]);
    TenantContext::current()->run($this->world->identity->beta, fn () => IntegrationConnection::factory()->create(['config' => ['url' => 'https://hooks.example.com/beta', 'events' => ['candidate.created']]]));

    $candidate = Candidate::factory()->create(['email' => 'priya.private@example.com']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($candidate): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://hooks.example.com/a'
            && signedWith($request, IntegrationConnectionFactory::SECRET)
            // MySQL's JSON type orders object keys its own way: compare the fields, not their order.
            && $body['type'] === 'candidate.created' && count($body['data']) === 2 && $body['data']['object'] === 'candidate' && $body['data']['id'] === $candidate->id
            && $request->header('RE-Event-Id')[0] === $body['id']
            && ! str_contains($request->body(), 'priya.private');
    });

    expect(WebhookDelivery::query()->sole())->integration_connection_id->toBe($subscribed->id)->status->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($subscribed->fresh()->last_success_at)->not->toBeNull()
        ->and(TenantContext::current()->run($this->world->identity->beta, fn (): int => WebhookEvent::query()->count()))->toBe(0);
});

test('nothing is recorded or sent when the tenant is not entitled to webhooks', function (): void {
    Http::fake();
    IntegrationConnection::factory()->create();
    ApiWorld::enable($this->tenant, webhooks: false);
    TenantContext::current()->setTenant($this->tenant->fresh());

    Candidate::factory()->create();

    Http::assertNothingSent();
    expect(WebhookEvent::query()->count())->toBe(0);
});

test('a failing endpoint is retried on schedule, then the delivery fails and is audited', function (): void {
    config(['api.webhooks.retry_delays' => [60, 300]]);
    Http::fake(['*' => Http::response('down', 503)]);
    $endpoint = IntegrationConnection::factory()->create();
    Candidate::factory()->create();
    $delivery = WebhookDelivery::query()->sole();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Retrying)
        ->and($delivery->next_attempt_at->timestamp)->toBe(now()->addSeconds(60)->timestamp)
        ->and(app(WebhookDeliveryService::class)->deliver($delivery->id))->toBe('not due');

    $this->travel(61)->seconds();
    expect(app(WebhookDeliveryService::class)->deliver($delivery->id))->toBe('retrying');

    $this->travel(301)->seconds();
    expect(app(WebhookDeliveryService::class)->deliver($delivery->id))->toBe('failed')
        ->and($delivery->fresh())->attempts->toBe(3)->response_status->toBe(503)->response_excerpt->toBe('down')
        ->and($endpoint->fresh()->consecutive_failures)->toBe(3)
        ->and(AuditLog::query()->where('action', 'webhook_delivery_failed')->count())->toBe(1);
    Http::assertSentCount(3);
});

test('a finished delivery is never sent again by a duplicate or late job', function (WebhookDeliveryStatus $finished): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    IntegrationConnection::factory()->create();
    Candidate::factory()->create();
    $delivery = WebhookDelivery::query()->sole();
    $delivery->forceFill(['status' => $finished])->save();

    DeliverWebhook::dispatch($delivery->id);

    expect(app(WebhookDeliveryService::class)->deliver($delivery->id))->toBe('not due')
        ->and($delivery->fresh())->attempts->toBe(1)->status->toBe($finished);
    Http::assertSentCount(1);
})->with([WebhookDeliveryStatus::Succeeded, WebhookDeliveryStatus::Failed]);

test('a redirect is not followed', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/'])]);
    IntegrationConnection::factory()->create();

    Candidate::factory()->create();

    Http::assertSentCount(1);
    expect(WebhookDelivery::query()->sole())->status->toBe(WebhookDeliveryStatus::Retrying)->response_status->toBe(302);
});

test('a delivery is not sent once the endpoint is disabled, the entitlement is gone, or the name now resolves privately', function (string $change): void {
    Http::fake(['*' => Http::response('down', 503)]);
    $endpoint = IntegrationConnection::factory()->create();
    Candidate::factory()->create();
    $delivery = WebhookDelivery::query()->sole();
    Http::fake();

    match ($change) {
        'disabled' => $this->connections->disable($endpoint, $this->world->identity->adminA, 'Paused'),
        'unentitled' => app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::IntegrationsWebhooks, false, 'Off'),
        'rebound' => $this->resolver->answers['hooks.example.com'] = ['10.0.0.9'],
    };
    TenantContext::current()->setTenant($this->tenant->fresh());
    $this->travel(61)->seconds();

    expect(app(WebhookDeliveryService::class)->deliver($delivery->id))->toBe('failed');
    Http::assertNothingSent();
})->with(['disabled', 'unentitled', 'rebound']);

test('a finished delivery can be replayed once at a time, with the same event id', function (): void {
    Http::fake(['*' => Http::sequence()->push('ok', 200)->push('', 500)]);
    IntegrationConnection::factory()->create();
    Candidate::factory()->create();
    $delivery = WebhookDelivery::query()->sole();

    $this->connections->replayDelivery($delivery, $this->world->identity->adminA);

    expect($delivery->fresh())->replay_count->toBe(1)->status->toBe(WebhookDeliveryStatus::Retrying)
        ->and(fn () => $this->connections->replayDelivery($delivery->fresh(), $this->world->identity->adminA))->toThrow(DomainException::class, 'already queued')
        ->and(AuditLog::query()->where('action', 'webhook_delivery_replayed')->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->header('RE-Event-Id')[0] === $delivery->event->event_key);
});

test('after rotation both secrets sign until the overlap ends, then only the new one', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $endpoint = IntegrationConnection::factory()->create();
    $rotated = $this->connections->rotateSecret($endpoint, $this->world->identity->adminA);

    Candidate::factory()->create();
    Http::assertSent(fn (Request $request): bool => signedWith($request, $rotated['secret']) && signedWith($request, IntegrationConnectionFactory::SECRET));

    $this->travel(25)->hours();
    Http::fake();
    Candidate::factory()->create();
    Http::assertSent(fn (Request $request): bool => signedWith($request, $rotated['secret']) && ! signedWith($request, IntegrationConnectionFactory::SECRET));

    expect(json_encode(AuditLog::query()->get()))->not->toContain($rotated['secret']);
});

test('only integrations.manage holders change connections; disabling needs no entitlement', function (): void {
    $endpoint = IntegrationConnection::factory()->create();

    expect(fn () => $this->connections->createOutbound($this->world->identity->personB, 'x', 'https://hooks.example.com/x', [WebhookEventType::CandidateCreated]))->toThrow(DomainException::class, 'integrations.manage')
        ->and(fn () => $this->connections->rotateSecret($endpoint, $this->world->identity->personB))->toThrow(DomainException::class, 'integrations.manage');

    app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::IntegrationsWebhooks, false, 'Incident');
    TenantContext::current()->setTenant($this->tenant->fresh());

    expect(fn () => $this->connections->rotateSecret($endpoint, $this->world->identity->adminA))->toThrow(DomainException::class)
        ->and($this->connections->disable($endpoint, $this->world->identity->adminA, 'Incident')->status)->toBe(ConnectionStatus::Disabled);
});
