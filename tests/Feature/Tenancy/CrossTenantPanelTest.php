<?php

use App\Enums\TenantMembershipStatus;
use App\Enums\TenantStatus;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\StaffDatabaseNotification;
use App\Services\Identity\RoleAssignmentService;
use App\Services\Identity\StaffAccessService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Livewire\Livewire;
use Tests\Feature\Tenancy\TenantWorld;

use function Pest\Laravel\actingAs;

/*
 * SaaS-1: Tenant A (ALPHA) can never read, change or find Tenant B (BRAVO) through the admin
 * panel — checked as each tenant's CHRO, who has "view all" (hierarchy.view-all): the exact
 * historical failure mode, where view-all meant the whole database.
 */
beforeEach(function (): void {
    $this->alpha = TenantWorld::build(Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Hiring']), 'ALPHA');
    $this->bravo = TenantWorld::build(Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Hiring']), 'BRAVO');
    $this->actInTenant($this->alpha->tenant);
    actingAs($this->alpha->chro);
});

test('a CHRO enters their own tenant and gets a 404 for another tenant\'s panel', function (): void {
    $this->get('/admin/alpha')->assertOk();
    $this->get('/admin/bravo')->assertNotFound();
    $this->get('/admin/bravo/candidates')->assertNotFound();
});

test('signing in lands on the person\'s own tenant', function (): void {
    expect($this->alpha->chro->getDefaultTenant(Filament::getPanel('admin'))?->is($this->alpha->tenant))->toBeTrue()
        ->and($this->alpha->chro->canAccessTenant($this->bravo->tenant))->toBeFalse()
        ->and($this->bravo->chro->canAccessTenant($this->alpha->tenant))->toBeFalse();
});

test('another tenant\'s records are a 404 inside a tenant, by id, on every kind of page', function (): void {
    $bravo = $this->bravo;

    foreach ([
        "/admin/alpha/candidates/{$bravo->candidate->id}",
        "/admin/alpha/candidates/{$bravo->candidate->id}/edit",
        "/admin/alpha/candidate-applications/{$bravo->application->id}",
        "/admin/alpha/recruitment-requisitions/{$bravo->requisition->id}",
        "/admin/alpha/offers/{$bravo->offer->id}",
        "/admin/alpha/employees/{$bravo->chroEmployee->id}/edit",
        "/admin/alpha/users/{$bravo->chro->id}/edit",
        '/admin/alpha/roles/'.TenantContext::current()->run($bravo->tenant, fn () => Role::byKey('chro')->id).'/edit',
        "/admin/alpha/ai-conversations/{$bravo->conversation->id}",
        "/admin/alpha/automation-rules/{$bravo->rule->id}/edit",
    ] as $url) {
        expect($this->get($url)->status())->toBe(404, $url);
    }
});

test('every resource lists only its own tenant\'s records, even for a view-all user', function (): void {
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        /** @var class-string<resource> $resource */
        $query = $resource::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
        $model = $query->getModel();

        if (! $model->isFillable('tenant_id') && ! in_array('tenant_id', $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable()), true)) {
            continue;
        }

        $tenants = $query->pluck($model->qualifyColumn('tenant_id'))->unique()->values()->all();

        expect(array_diff($tenants, [$this->alpha->tenant->id]))->toBe([], $resource);
    }
});

test('global search finds the tenant\'s own records and nothing of the other tenant', function (): void {
    $own = Filament::getGlobalSearchProvider()->getResults('ALPHA Candidate');
    $foreign = Filament::getGlobalSearchProvider()->getResults('BRAVO');

    expect(collect($own?->getCategories())->flatten()->map(fn ($result) => $result->title)->implode(' | '))->toContain('ALPHA Candidate')
        ->and(collect($foreign?->getCategories())->flatten()->count())->toBe(0);
});

test('a table shows its own records, and its actions cannot reach another tenant\'s record by key', function (): void {
    Livewire::test(ListCandidates::class)
        ->assertCanSeeTableRecords([$this->alpha->candidate])
        ->assertCanNotSeeTableRecords([$this->bravo->candidate]);

    // Every table and bulk action resolves the records it acts on from client-sent keys through
    // the table's query; another tenant's key resolves to nothing.
    $table = Livewire::test(ListCandidates::class)->instance();

    expect($table->getTableRecord((string) $this->bravo->candidate->id))->toBeNull()
        ->and($table->getTableRecord((string) $this->alpha->candidate->id)?->is($this->alpha->candidate))->toBeTrue()
        ->and(TenantContext::current()->run($this->bravo->tenant, fn () => Candidate::query()->whereKey($this->bravo->candidate->id)->exists()))->toBeTrue();
});

test('staff identities, roles and access stay inside the tenant', function (): void {
    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$this->alpha->recruiter])
        ->assertCanNotSeeTableRecords([$this->bravo->chro, $this->bravo->recruiter]);

    $bravoChroRole = TenantContext::current()->run($this->bravo->tenant, fn () => Role::byKey('chro'));
    app(RoleAssignmentService::class)->syncUserRoles($this->alpha->recruiter, [$bravoChroRole->id], $this->alpha->chro);

    expect($this->alpha->recruiter->fresh()->roles()->pluck('roles.id')->all())->not->toContain($bravoChroRole->id)
        ->and(fn () => app(StaffAccessService::class)->suspend($this->bravo->recruiter, $this->alpha->chro, 'Not ours'))->toThrow(DomainException::class)
        ->and($this->bravo->recruiter->fresh()->access_status->value)->toBe('active')
        ->and(Role::byKey('chro')->tenant_id)->toBe($this->alpha->tenant->id);
});

test('a person in two tenants sees each tenant\'s own roles, permissions and notifications only', function (): void {
    TenantMembership::query()->create(['tenant_id' => $this->bravo->tenant->id, 'user_id' => $this->alpha->recruiter->id, 'status' => TenantMembershipStatus::Active]);
    TenantContext::current()->run($this->bravo->tenant, function (): void {
        $this->alpha->recruiter->unsetRelation('roles')->assignRole('chro');
        $this->alpha->recruiter->notify(new StaffDatabaseNotification(Notification::make()->title('BRAVO only')->getDatabaseMessage()));
    });

    $person = $this->alpha->recruiter->fresh();

    expect($person->getTenants(Filament::getPanel('admin'))->pluck('slug')->all())->toBe(['alpha', 'bravo'])
        ->and($person->hasRole('chro'))->toBeFalse()
        ->and($person->can('hierarchy.view-all'))->toBeFalse()
        ->and($person->notifications()->pluck('data')->map(fn ($data) => $data['title'] ?? null)->all())->not->toContain('BRAVO only')
        ->and(TenantContext::current()->run($this->bravo->tenant, fn () => $person->fresh()->hasRole('chro')))->toBeTrue();
});

test('a revoked membership or an unusable tenant closes the door', function (): void {
    TenantMembership::query()->where('tenant_id', $this->bravo->tenant->id)->where('user_id', $this->bravo->chro->id)->update(['status' => TenantMembershipStatus::Revoked->value]);

    expect($this->bravo->chro->fresh()->canAccessTenant($this->bravo->tenant))->toBeFalse();

    $this->alpha->tenant->update(['status' => TenantStatus::Suspended]);

    expect($this->alpha->chro->fresh()->canAccessTenant($this->alpha->tenant->fresh()))->toBeFalse()
        ->and($this->get('/admin/alpha')->status())->toBeIn([403, 404]);
});

test('the audit log shows the tenant\'s own stream only — never the other tenant\'s or the platform\'s', function (): void {
    $platform = TenantContext::current()->runWithoutTenant(fn () => AuditLog::record(User::factory()->create(), 'platform_event', null, null));
    $bravoEntry = TenantContext::current()->run($this->bravo->tenant, fn () => AuditLog::record($this->bravo->candidate, 'updated', null, ['full_name' => 'BRAVO Candidate']));
    $alphaEntry = AuditLog::record($this->alpha->candidate, 'updated', null, ['full_name' => 'ALPHA Candidate']);

    expect($platform->tenant_id)->toBeNull()
        ->and($bravoEntry->tenant_id)->toBe($this->bravo->tenant->id);

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$alphaEntry])
        ->assertCanNotSeeTableRecords([$bravoEntry, $platform]);
});
