<?php

use App\Enums\CommunicationStatus;
use App\Enums\InboundWebhookStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Filament\Exports\Concerns\RunsOnExportQueue;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessBillingEvent;
use App\Jobs\RunTenantScheduledTask;
use App\Jobs\SendCommunicationJob;
use App\Models\CandidateCommunication;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantTasks;
use App\Services\Tenancy\TenantUnavailable;
use App\Services\Webhooks\InboundWebhookProcessor;
use App\Services\Webhooks\WebhookDeliveryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Api\ApiWorld;
use Tests\Feature\Api\FakeHostResolver;

/*
 * SaaS-7 (C4, C6): queue hardening and per-tenant fairness for integration work — deferred, never
 * dropped.
 */
beforeEach(function (): void {
    FakeHostResolver::install();
});

/**
 * Pending deliveries to one endpoint of the current tenant, without dispatching anything.
 *
 * @return list<int>
 */
function queueHardeningDeliveries(int $count, ?IntegrationConnection $endpoint = null): array
{
    $endpoint ??= IntegrationConnection::factory()->create();
    $ids = [];

    foreach (range(1, $count) as $n) {
        $event = WebhookEvent::query()->create(['event_key' => 'evt_'.Str::lower(Str::random(12)), 'type' => 'candidate.created', 'subject_type' => 'candidate', 'subject_id' => $n, 'payload' => ['object' => 'candidate', 'id' => $n], 'occurred_at' => now()]);
        $ids[] = WebhookDelivery::query()->create(['webhook_event_id' => $event->id, 'integration_connection_id' => $endpoint->id, 'delivery_key' => 'dlv_'.Str::lower(Str::random(12)), 'status' => WebhookDeliveryStatus::Pending, 'next_attempt_at' => now()])->id;
    }

    return $ids;
}

test('a job refused only because its tenant is paused leaves its work to be resumed; any other failure finalises it', function (): void {
    $queued = CandidateCommunication::factory()->create(['status' => CommunicationStatus::Queued, 'queued_at' => now()]);
    $other = CandidateCommunication::factory()->create(['status' => CommunicationStatus::Queued, 'queued_at' => now()]);

    (new SendCommunicationJob($queued->id))->failed(TenantUnavailable::forTenant($this->tenant->id, 'its status is suspended'));
    (new SendCommunicationJob($other->id))->failed(new RuntimeException('provider down'));

    expect($queued->fresh()->status)->toBe(CommunicationStatus::Queued)
        ->and($other->fresh()->status)->toBe(CommunicationStatus::Failed);
});

test('every tenant task stops before its own worker would kill it, and none runs on `default`', function (): void {
    preg_match_all('/command: \["php", "artisan", "queue:work", "--queue=([^"]+)", "--tries=\d+", "--timeout=(\d+)"/', (string) file_get_contents(base_path('docker-compose.yml')), $workers, PREG_SET_ORDER);
    $timeoutOf = [];

    foreach ($workers as [, $queues, $timeout]) {
        foreach (explode(',', $queues) as $queue) {
            $timeoutOf[$queue] ??= (int) $timeout;
        }
    }

    foreach (array_keys(TenantTasks::QUEUED) as $task) {
        $job = new RunTenantScheduledTask($task);

        expect($job->queue)->not->toBe('default', "{$task} runs on default")
            ->and($job->timeout)->toBeLessThan($timeoutOf[$job->queue], "{$task} outlives its worker's --timeout");
    }

    expect((new ProcessBillingEvent(1))->queue)->toBe('billing')
        ->and($timeoutOf)->toHaveKey('billing');
});

test('one queued delivery job per delivery: re-dispatching a waiting delivery adds nothing', function (): void {
    Queue::fake();
    [$id] = queueHardeningDeliveries(1);

    DeliverWebhook::dispatch($id);
    DeliverWebhook::dispatch($id);
    $this->artisan('integrations:sweep')->assertSuccessful();

    Queue::assertPushed(DeliverWebhook::class, 1);
});

test('past its per-minute budget a tenant\'s deliveries wait, still due, and are sent by a later sweep — another tenant is unaffected', function (): void {
    config(['api.webhooks.tenant_deliveries_per_minute' => 2]);
    Http::fake(['*' => Http::response('ok', 200)]);
    $world = ApiWorld::build($this->tenant);
    $ids = queueHardeningDeliveries(4);
    $theirs = TenantContext::current()->run($world->identity->beta->fresh(), fn () => queueHardeningDeliveries(1));

    $outcomes = array_map(fn (int $id): string => app(WebhookDeliveryService::class)->deliver($id), $ids);
    $theirOutcome = TenantContext::current()->run($world->identity->beta->fresh(), fn (): string => app(WebhookDeliveryService::class)->deliver($theirs[0]));

    expect($outcomes)->toBe(['succeeded', 'succeeded', 'deferred', 'deferred'])
        ->and($theirOutcome)->toBe('succeeded')
        ->and(WebhookDelivery::query()->whereKey($ids[3])->sole())->status->toBe(WebhookDeliveryStatus::Pending)->attempts->toBe(0);
    Http::assertSentCount(3);

    $this->travel(61)->seconds();
    $this->artisan('integrations:sweep')->assertSuccessful();

    expect(WebhookDelivery::query()->whereIn('id', $ids)->pluck('status')->unique()->values()->all())->toBe([WebhookDeliveryStatus::Succeeded]);
});

test('an endpoint failing again and again gets one trial delivery per cool-off, then closes the circuit on success', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    ApiWorld::build($this->tenant);
    $endpoint = IntegrationConnection::factory()->create(['consecutive_failures' => 5, 'last_failure_at' => now()->subMinute()]);
    [$id] = queueHardeningDeliveries(1, $endpoint);

    expect(app(WebhookDeliveryService::class)->deliver($id))->toBe('deferred')
        ->and(WebhookDelivery::query()->whereKey($id)->sole()->next_attempt_at->timestamp)->toBe($endpoint->last_failure_at->addSeconds(300)->timestamp);
    Http::assertNothingSent();

    $this->travel(5)->minutes();

    expect(app(WebhookDeliveryService::class)->deliver($id))->toBe('succeeded')
        ->and($endpoint->fresh()->consecutive_failures)->toBe(0);
});

test('past its per-minute budget a tenant\'s inbound events wait as received and are processed later', function (): void {
    config(['api.webhooks.tenant_inbound_per_minute' => 1]);
    $source = IntegrationConnection::factory()->inbound()->create();
    $events = collect(range(1, 2))->map(fn (int $n) => InboundWebhookEvent::query()->create(['integration_connection_id' => $source->id, 'external_id' => "evt_{$n}", 'type' => 'other.event', 'payload_encrypted' => ['id' => "evt_{$n}", 'type' => 'other.event'], 'payload_hash' => str_repeat('a', 64), 'status' => InboundWebhookStatus::Received, 'received_at' => now()]));

    expect(app(InboundWebhookProcessor::class)->process($events[0]->id))->toBe('ignored')
        ->and(app(InboundWebhookProcessor::class)->process($events[1]->id))->toBe('deferred')
        ->and($events[1]->fresh())->status->toBe(InboundWebhookStatus::Received)->attempts->toBe(0);

    $this->travel(61)->seconds();

    expect(app(InboundWebhookProcessor::class)->process($events[1]->id))->toBe('ignored');
});

test('an export chunk is retried for an hour, not a day', function (): void {
    $exporter = new class
    {
        use RunsOnExportQueue;
    };

    expect($exporter->getJobRetryUntil()->timestamp)->toBe(now()->addHour()->timestamp)
        ->and($exporter->getJobQueue())->toBe('exports');
});
