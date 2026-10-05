<?php

use App\Filament\Pages\AccessReview;
use App\Jobs\ProcessOwnershipHandoffJob;
use App\Models\OwnershipHandoff;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-7: the two tenancy crossings found by the discovery audit (S7-06, S7-07) — a person who
 * belongs to two tenants is judged, and handed off, in each tenant separately.
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
});

function isolationFixesHandoff(int $userId, string $key): OwnershipHandoff
{
    return OwnershipHandoff::query()->create(['dedupe_key' => $key, 'user_id' => $userId, 'trigger' => 'suspended', 'status' => OwnershipHandoff::OPEN]);
}

test('an open handoff in another tenant does not put a shared member on this tenant\'s attention list', function (): void {
    TenantContext::current()->run($this->world->beta, fn () => isolationFixesHandoff($this->world->personA->id, 'beta-handoff'));
    $ownHandoff = isolationFixesHandoff($this->world->personB->id, 'acme-handoff');
    $this->actingAs($this->world->adminA);

    Livewire::test(AccessReview::class)
        ->filterTable('needs_attention', true)
        ->assertCanSeeTableRecords([$this->world->personB])
        ->assertCanNotSeeTableRecords([$this->world->personA]);

    expect($ownHandoff->status)->toBe(OwnershipHandoff::OPEN);
});

test('the same loss of access in two tenants queues a handoff in each — the unique lock names the tenant', function (): void {
    Queue::fake();
    $dispatch = fn () => ProcessOwnershipHandoffJob::dispatch($this->world->personA->id, null, 'suspended', "{$this->world->personA->id}:suspended:1790000000");

    TenantContext::current()->run($this->world->acme, function () use ($dispatch): void {
        $dispatch();
    });
    TenantContext::current()->run($this->world->beta, function () use ($dispatch): void {
        $dispatch();
    });
    TenantContext::current()->run($this->world->beta, function () use ($dispatch): void {
        $dispatch();
    });

    Queue::assertPushed(ProcessOwnershipHandoffJob::class, 2);
    expect(Queue::pushed(ProcessOwnershipHandoffJob::class)->map->tenantId->all())->toBe([$this->world->acme->id, $this->world->beta->id]);
});
