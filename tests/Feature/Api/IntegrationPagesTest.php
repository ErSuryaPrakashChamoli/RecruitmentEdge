<?php

use App\Enums\ConnectionStatus;
use App\Enums\InboundWebhookStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Filament\Pages\InboundWebhookEvents;
use App\Filament\Pages\IntegrationConnections;
use App\Filament\Pages\WebhookDeliveries;
use App\Models\Candidate;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Models\WebhookDelivery;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Api\ApiWorld;
use Tests\Feature\Api\FakeHostResolver;

/*
 * SaaS-6 panel pages: integrations.manage holders add endpoints and sources (the secret shown once),
 * disable them, replay deliveries and reprocess inbound events — in their own tenant only.
 */
beforeEach(function (): void {
    $this->world = ApiWorld::build($this->tenant);
    FakeHostResolver::install(['internal.example.com' => ['10.0.0.5']]);
    $this->actingAs($this->world->identity->adminA);
});

test('an endpoint added on the Webhooks page shows its secret once; an unsafe URL is refused', function (): void {
    $page = Livewire::test(IntegrationConnections::class)
        ->callTableAction('createOutbound', data: ['name' => 'ATS', 'url' => 'https://hooks.example.com/in', 'events' => ['candidate.created']])
        ->assertActionMounted('revealSecret');

    $endpoint = IntegrationConnection::query()->where('name', 'ATS')->sole();
    expect($page->instance()->mountedActions[0]['arguments']['secret'] ?? null)->toBe($endpoint->secrets['current']);

    Livewire::test(IntegrationConnections::class)
        ->callTableAction('createOutbound', data: ['name' => 'Internal', 'url' => 'https://internal.example.com/in', 'events' => ['candidate.created']])
        ->assertNotified();
    expect(IntegrationConnection::query()->where('name', 'Internal')->exists())->toBeFalse();

    Livewire::test(IntegrationConnections::class)->callTableAction('disable', $endpoint, ['reason' => 'Paused']);
    expect($endpoint->fresh()->status)->toBe(ConnectionStatus::Disabled);
});

test('the inbound source\'s URL and secret are shown once', function (): void {
    $page = Livewire::test(IntegrationConnections::class)
        ->callTableAction('createInbound', data: ['name' => 'Job board', 'handler' => 'applications.submit'])
        ->assertActionMounted('revealSecret');

    $source = IntegrationConnection::query()->where('name', 'Job board')->sole();
    expect($page->instance()->mountedActions[0]['arguments'])->toMatchArray(['secret' => $source->secrets['current'], 'url' => route('api.v1.hooks.receive', $source->public_key)]);
});

test('deliveries are replayed and inbound events reprocessed from their pages, in this tenant only', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    IntegrationConnection::factory()->create();
    Candidate::factory()->create();
    $delivery = WebhookDelivery::query()->sole();
    $source = IntegrationConnection::factory()->inbound()->create();
    $ignored = InboundWebhookEvent::query()->create(['integration_connection_id' => $source->id, 'external_id' => 'evt_1', 'type' => 'x', 'payload_encrypted' => ['id' => 'evt_1', 'type' => 'x'], 'payload_hash' => str_repeat('a', 64), 'status' => InboundWebhookStatus::Ignored, 'received_at' => now()]);
    $theirs = TenantContext::current()->run($this->world->identity->beta, fn () => IntegrationConnection::factory()->create(['name' => 'Beta endpoint']));
    Queue::fake();

    Livewire::test(WebhookDeliveries::class)->assertCanSeeTableRecords([$delivery])->callTableAction('replay', $delivery);
    Livewire::test(InboundWebhookEvents::class)->assertCanSeeTableRecords([$ignored])->callTableAction('reprocess', $ignored);
    Livewire::test(IntegrationConnections::class)->assertCanNotSeeTableRecords([$theirs]);

    expect($delivery->fresh())->status->toBe(WebhookDeliveryStatus::Pending)->replay_count->toBe(1)
        ->and($ignored->fresh()->status)->toBe(InboundWebhookStatus::Received);
});

test('members without integrations.manage reach none of the pages', function (string $page): void {
    $this->actingAs($this->world->identity->personB)->get($page::getUrl())->assertForbidden();
})->with([IntegrationConnections::class, WebhookDeliveries::class, InboundWebhookEvents::class]);
