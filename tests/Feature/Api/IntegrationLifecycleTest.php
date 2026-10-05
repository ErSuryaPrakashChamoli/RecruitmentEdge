<?php

use App\Enums\InboundWebhookStatus;
use App\Enums\TenantStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessInboundWebhook;
use App\Models\ApiCredential;
use App\Models\ApiIdempotencyKey;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\ComplianceExport;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Platform\ComplianceExportService;
use App\Services\Platform\TenantPurgePlan;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantUnavailable;
use App\Services\Webhooks\InboundWebhookProcessor;
use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\ApiWorld;
use Tests\Feature\Api\FakeHostResolver;
use Tests\Feature\Platform\PlatformWorld;

/*
 * SaaS-6 records follow the tenant's lifecycle: a closed tenant's integration work never runs, the
 * sweep retries and prunes per tenant, the SaaS-5 purge removes them and compliance exports never
 * carry their secrets.
 */
beforeEach(function (): void {
    FakeHostResolver::install();
});

/**
 * A pending outbound delivery and a received inbound event, nothing sent or processed yet.
 *
 * @return array{delivery: WebhookDelivery, inbound: InboundWebhookEvent, source: IntegrationConnection}
 */
function pendingIntegrationWork(int $postingId): array
{
    $queue = Queue::getFacadeRoot();
    Queue::fake();
    IntegrationConnection::factory()->create();
    Candidate::factory()->create();
    $source = IntegrationConnection::factory()->inbound()->create();
    $inbound = InboundWebhookEvent::query()->create([
        'integration_connection_id' => $source->id, 'external_id' => 'evt_1', 'type' => 'application.submitted', 'status' => InboundWebhookStatus::Received,
        'payload_encrypted' => ['id' => 'evt_1', 'type' => 'application.submitted', 'data' => ['job_posting_id' => $postingId, 'applicant' => ['full_name' => 'Arjun Rao', 'email' => 'arjun.rao@example.com', 'mobile' => '+91 98860 54321', 'privacy_consent' => true]]],
        'payload_hash' => str_repeat('a', 64), 'received_at' => now(),
    ]);
    Queue::swap($queue);

    return ['delivery' => WebhookDelivery::query()->sole(), 'inbound' => $inbound, 'source' => $source];
}

/**
 * @return array{manifest: array<string, mixed>, tables: array<string, list<array<string, mixed>>>}
 */
function integrationExportArchive(ComplianceExport $export): array
{
    $local = tempnam(sys_get_temp_dir(), 'export-test');
    file_put_contents($local, Storage::disk($export->disk)->get($export->path));
    $zip = new ZipArchive;
    $zip->open($local);
    $tables = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (str_starts_with($name, 'data/')) {
            $tables[basename($name, '.jsonl')] = array_map(fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", (string) $zip->getFromIndex($i)))));
        }
    }

    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();
    unlink($local);

    return ['manifest' => $manifest, 'tables' => $tables];
}

test('a closed tenant\'s queued integration work is refused by the queue guard and by the services themselves', function (): void {
    ApiWorld::build($this->tenant);
    $work = pendingIntegrationWork(ApiWorld::livePosting($this->tenant)->id);
    Http::fake();
    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();
    TenantContext::current()->setTenant($this->tenant->fresh());

    expect(fn () => DeliverWebhook::dispatch($work['delivery']->id))->toThrow(TenantUnavailable::class)
        ->and(fn () => ProcessInboundWebhook::dispatch($work['inbound']->id))->toThrow(TenantUnavailable::class)
        // Refused work stays as it was, for SaaS-3 to queue again when the tenant is usable.
        ->and($work['inbound']->fresh()->status)->toBe(InboundWebhookStatus::Received)
        ->and(app(InboundWebhookProcessor::class)->process($work['inbound']->id))->toBe('failed')
        ->and($work['delivery']->fresh()->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and(CandidateApplication::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('the sweep resends stalled and due deliveries, processes stranded inbound events and prunes expired records', function (): void {
    ApiWorld::build($this->tenant);
    $work = pendingIntegrationWork(ApiWorld::livePosting($this->tenant)->id);
    Http::fake(['*' => Http::response('ok', 200)]);
    $work['delivery']->forceFill(['status' => WebhookDeliveryStatus::Sending, 'claimed_until' => now()->subMinute()])->save();
    $this->travel(6)->minutes();

    $old = WebhookEvent::query()->create(['event_key' => 'evt_old', 'type' => 'candidate.created', 'subject_type' => 'candidate', 'subject_id' => 1, 'payload' => [], 'occurred_at' => now()->subDays(31)]);
    WebhookDelivery::query()->create(['webhook_event_id' => $old->id, 'integration_connection_id' => $work['delivery']->integration_connection_id, 'delivery_key' => 'dlv_old', 'status' => WebhookDeliveryStatus::Succeeded]);
    $oldRetrying = WebhookEvent::query()->create(['event_key' => 'evt_old_retry', 'type' => 'candidate.created', 'subject_type' => 'candidate', 'subject_id' => 2, 'payload' => [], 'occurred_at' => now()->subDays(31)]);
    WebhookDelivery::query()->create(['webhook_event_id' => $oldRetrying->id, 'integration_connection_id' => $work['delivery']->integration_connection_id, 'delivery_key' => 'dlv_old_retry', 'status' => WebhookDeliveryStatus::Retrying, 'next_attempt_at' => now()->addHour()]);
    ApiIdempotencyKey::query()->create(['api_credential_id' => ApiCredential::query()->value('id'), 'idempotency_key' => 'k', 'method' => 'POST', 'route' => 'x', 'request_hash' => str_repeat('b', 64), 'status' => 'completed', 'expires_at' => now()->subMinute()]);

    $this->artisan('integrations:sweep')->assertSuccessful();

    expect($work['delivery']->fresh()->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($work['inbound']->fresh()->status)->toBe(InboundWebhookStatus::Processed)
        ->and(WebhookEvent::query()->pluck('event_key')->all())->not->toContain('evt_old')->toContain('evt_old_retry')
        ->and(ApiIdempotencyKey::query()->count())->toBe(0);
});

test('the sweep is a queued tenant task', function (): void {
    $this->artisan('tenants:dispatch', ['task' => 'integrations:sweep'])->assertSuccessful();
    $this->artisan('integrations:sweep')->assertSuccessful();
    expect(fn () => TenantContext::current()->runWithoutTenant(fn () => $this->artisan('integrations:sweep')->run()))->toThrow('No tenant context');
});

test('the purge removes every SaaS-6 table\'s rows; a compliance export carries them without any secret', function (): void {
    $platform = PlatformWorld::build($this->tenant);
    ApiWorld::enable($this->tenant);
    $token = ApiWorld::issue($this->tenant, $platform->identity->adminA, 'Exported');
    $work = pendingIntegrationWork(ApiWorld::livePosting($this->tenant)->id);
    Storage::fake('local');

    expect(TenantPurgePlan::build()->tables)->toContain('api_credentials', 'api_idempotency_keys', 'integration_connections', 'webhook_events', 'webhook_deliveries', 'inbound_webhook_events');

    $export = app(ComplianceExportService::class)->request($this->tenant, 'Exit export', $platform->compliance)->fresh();
    $archive = integrationExportArchive($export);
    $raw = json_encode($archive['tables']);

    expect($archive['tables'])->toHaveKeys(['api_credentials', 'integration_connections', 'webhook_deliveries', 'inbound_webhook_events'])
        ->and($archive['manifest']['excluded_columns']['api_credentials'])->toContain('secret_hash')
        ->and($archive['manifest']['excluded_columns']['integration_connections'])->toContain('secrets')
        ->and($archive['manifest']['excluded_columns']['inbound_webhook_events'])->toContain('payload_encrypted')
        ->and($raw)->not->toContain(explode('_', $token, 3)[2])->not->toContain(IntegrationConnectionFactory::SECRET)->not->toContain('arjun.rao');
});
