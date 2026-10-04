<?php

use App\Enums\AccessState;
use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\RecruitmentRequisitions\Pages\CreateRecruitmentRequisition;
use App\Filament\Resources\RecruitmentRequisitions\Pages\ListRecruitmentRequisitions;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use App\Models\RecruitmentRequisition;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Entitlements\EntitlementDenied;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\LimitReached;
use App\Services\Identity\StaffAccessService;
use App\Services\Identity\TenantInvitationService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\RequisitionService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Commercial\CommercialWorld;

/*
 * SaaS-3: limits are enforced on the server, at the operation, atomically — never by a hidden
 * button. Permission and entitlement are separate gates: each refuses on its own.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->world = CommercialWorld::build();
});

function limitsActiveRequisitions(CommercialWorld $world, string $tenant, int $count): void
{
    $world->in($tenant, fn () => RecruitmentRequisition::factory()->count($count)->create(['status' => RequisitionStatus::Open]));
}

function limitsCreateThroughService(CommercialWorld $world, string $tenant, ?User $actor = null): RecruitmentRequisition
{
    return $world->in($tenant, fn () => app(RequisitionService::class)->create($actor ?? $world->admins[$tenant]->fresh(), fn () => RecruitmentRequisition::factory()->create()));
}

test('at the limit a new requisition is refused with the limit and usage, and nothing is created', function (): void {
    limitsActiveRequisitions($this->world, 'trialStarter', 10);

    try {
        limitsCreateThroughService($this->world, 'trialStarter');
        $this->fail('The 11th requisition was created.');
    } catch (LimitReached $e) {
        expect($e->limit)->toBe(10)->and($e->usage)->toBe(10)->and($e->getMessage())->toContain('In use: 10 of 10');
    }

    expect($this->world->in('trialStarter', fn () => RecruitmentRequisition::query()->count()))->toBe(10)
        // The backstop on the record refuses a path that did not go through the service.
        ->and(fn () => $this->world->in('trialStarter', fn () => RecruitmentRequisition::factory()->create()))->toThrow(LimitReached::class)
        ->and(limitsCreateThroughService($this->world, 'growth')->exists)->toBeTrue();
});

test('closed and cancelled requisitions are not active: closing one frees its place', function (): void {
    limitsActiveRequisitions($this->world, 'trialStarter', 10);
    $this->world->in('trialStarter', fn () => lifecycleFixture(fn () => RecruitmentRequisition::query()->first()->forceFill(['status' => RequisitionStatus::Closed])->save()));

    expect(limitsCreateThroughService($this->world, 'trialStarter')->exists)->toBeTrue()
        ->and($this->world->in('trialStarter', fn () => app(EntitlementService::class)->usage(Entitlement::RequisitionsActiveMax)))->toBe(10);
});

test('permission without room is refused, and room without permission is refused', function (): void {
    limitsActiveRequisitions($this->world, 'trialStarter', 10);
    $recruiterOnly = $this->world->in('growth', fn () => User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('employee'));

    expect(fn () => limitsCreateThroughService($this->world, 'trialStarter'))->toThrow(LimitReached::class)
        ->and(fn () => limitsCreateThroughService($this->world, 'growth', $recruiterOnly))->toThrow(DomainException::class, 'requisitions.create')
        ->and($this->world->in('growth', fn () => RecruitmentRequisition::query()->count()))->toBe(0);
});

test('the create button explains the limit, and a direct Livewire create is still refused', function (): void {
    limitsActiveRequisitions($this->world, 'trialStarter', 10);
    $this->actInTenant($this->world->tenant('trialStarter'));
    $this->actingAs($this->world->admins['trialStarter']->fresh());

    Livewire::test(ListRecruitmentRequisitions::class)->assertActionDisabled('create');

    Livewire::test(CreateRecruitmentRequisition::class)
        ->fillForm([
            'department_id' => Department::factory()->create()->id,
            'designation_id' => Designation::factory()->create()->id,
            'location_id' => Location::factory()->create()->id,
            'openings' => 1,
            'employment_type' => 'permanent',
            'priority' => 'medium',
        ])
        ->call('create')
        ->assertNotified('Requisition not created');

    expect(RecruitmentRequisition::query()->count())->toBe(10);
});

test('restoring an active requisition needs room; restoring a closed one does not', function (): void {
    limitsActiveRequisitions($this->world, 'trialStarter', 10);
    $admin = $this->world->admins['trialStarter']->fresh();
    [$active, $closed] = $this->world->in('trialStarter', function (): array {
        $active = RecruitmentRequisition::query()->first();
        $active->delete();
        $closed = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]);
        $closed->delete();
        RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

        return [$active, $closed];
    });

    expect(fn () => $this->world->in('trialStarter', fn () => app(RequisitionService::class)->restore($active->fresh(), $admin)))->toThrow(LimitReached::class)
        ->and($this->world->in('trialStarter', fn () => app(RequisitionService::class)->restore($closed->fresh(), $admin))->trashed())->toBeFalse()
        ->and($this->world->in('trialStarter', fn () => $active->fresh()->trashed()))->toBeTrue();
});

test('staff seats: a full plan refuses new invitations, an acceptance and a restore', function (): void {
    $tenant = 'trialStarter';
    $admin = $this->world->admins[$tenant]->fresh();
    $pending = $this->world->in($tenant, fn () => app(TenantInvitationService::class)->invite(['email' => 'late@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $admin));
    $members = $this->world->in($tenant, fn () => User::factory()->count(4)->create()->each->assignRole('recruiter'));

    expect($this->world->in($tenant, fn () => TenantMembership::query()->where('tenant_id', $this->world->tenants[$tenant]->id)->where('status', AccessState::Active->value)->count()))->toBe(5)
        ->and(fn () => $this->world->in($tenant, fn () => app(TenantInvitationService::class)->invite(['email' => 'one.more@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $admin)))->toThrow(EntitlementDenied::class)
        ->and(fn () => $this->world->in($tenant, fn () => app(TenantInvitationService::class)->acceptAsNewIdentity($pending, 'Late Person', 'Tr1cky-Ledger-Horse')))->toThrow(LimitReached::class)
        ->and(User::query()->where('email', 'late@example.test')->exists())->toBeFalse();

    $member = $members->first();
    $this->world->in($tenant, fn () => app(StaffAccessService::class)->suspend($member, $admin, 'Leave'));

    // The suspended member freed a seat; the late invitation can now be accepted — and then the
    // suspended member cannot come back until a seat is free again.
    expect($this->world->in($tenant, fn () => app(TenantInvitationService::class)->acceptAsNewIdentity($pending->fresh(), 'Late Person', 'Tr1cky-Ledger-Horse'))->exists)->toBeTrue()
        ->and(fn () => $this->world->in($tenant, fn () => app(StaffAccessService::class)->restore($member->fresh(), $admin, 'Back')))->toThrow(LimitReached::class);
});

test('a seat check in the Users screen: the invite button explains a full plan', function (): void {
    $tenant = 'trialStarter';
    $this->world->in($tenant, fn () => User::factory()->count(4)->create()->each->assignRole('recruiter'));
    $this->actInTenant($this->world->tenant($tenant));
    $this->actingAs($this->world->admins[$tenant]->fresh());

    Livewire::test(ListUsers::class)->assertActionDisabled('inviteMember');
});

test('a downgrade keeps every existing record and only stops new ones above the new limit', function (): void {
    limitsActiveRequisitions($this->world, 'growth', 12);
    $this->world->in('growth', fn () => User::factory()->count(6)->create()->each->assignRole('recruiter'));

    app(PlanAssignmentService::class)->assign($this->world->tenant('growth'), app(PlanAssignmentService::class)->latestVersion('starter'), 'platform', 'Downgrade');

    expect($this->world->in('growth', fn () => RecruitmentRequisition::query()->whereNotIn('status', ['closed', 'cancelled'])->count()))->toBe(12)
        ->and($this->world->in('growth', fn () => TenantMembership::query()->where('tenant_id', $this->world->tenants['growth']->id)->where('status', 'active')->count()))->toBe(7)
        ->and(fn () => limitsCreateThroughService($this->world, 'growth'))->toThrow(LimitReached::class);

    $this->world->in('growth', fn () => lifecycleFixture(fn () => RecruitmentRequisition::query()->limit(3)->get()->each(fn ($r) => $r->forceFill(['status' => RequisitionStatus::Cancelled])->save())));

    expect(limitsCreateThroughService($this->world, 'growth')->exists)->toBeTrue();
});

test('a tenant uses its own quota only — another tenant\'s records never count', function (): void {
    limitsActiveRequisitions($this->world, 'enterprise', 30);
    limitsActiveRequisitions($this->world, 'trialStarter', 9);

    expect(limitsCreateThroughService($this->world, 'trialStarter')->exists)->toBeTrue()
        ->and(fn () => limitsCreateThroughService($this->world, 'trialStarter'))->toThrow(LimitReached::class)
        ->and($this->world->in('enterprise', fn () => app(EntitlementService::class)->usage(Entitlement::RequisitionsActiveMax)))->toBe(30);
});
