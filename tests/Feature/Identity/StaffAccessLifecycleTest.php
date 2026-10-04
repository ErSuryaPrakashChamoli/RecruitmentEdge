<?php

use App\Enums\AccessState;
use App\Events\EmployeeAccessRestored;
use App\Events\EmployeeAccessRevoked;
use App\Events\EmployeeAccessSuspended;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\LastChroProtectedException;
use App\Services\Identity\SessionRevocationService;
use App\Services\Identity\StaffAccessService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->recruiterEmployee = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $this->recruiter = User::factory()->create(['employee_id' => $this->recruiterEmployee->id])->assignRole('recruiter');
    $this->access = app(StaffAccessService::class);
});

function identityPanel(): Panel
{
    return Filament::getPanel('admin');
}

test('an active login may use the panel and its permissions; a suspended or revoked one may not', function (): void {
    expect($this->recruiter->canAccessPanel(identityPanel()))->toBeTrue()
        ->and($this->recruiter->can('pipeline.transition'))->toBeTrue();

    $this->access->suspend($this->recruiter, $this->vp, 'Investigation');

    expect($this->recruiter->access_status)->toBe(AccessState::Suspended)
        ->and($this->recruiter->canAccessPanel(identityPanel()))->toBeFalse()
        ->and($this->recruiter->can('pipeline.transition'))->toBeFalse()
        ->and($this->recruiter->fresh()->can('pipeline.transition'))->toBeFalse()
        ->and($this->recruiter->fresh()->hasRole('recruiter'))->toBeTrue();

    $this->access->revoke($this->recruiter, $this->vp, 'Left the company');

    expect($this->recruiter->fresh()->access_status)->toBe(AccessState::Revoked)
        ->and($this->recruiter->fresh()->canAccessPanel(identityPanel()))->toBeFalse();
});

test('revoking removes every role and records what was held; restoring grants only the base role', function (): void {
    $this->recruiter->assignRole('manager');

    $this->access->revoke($this->recruiter, $this->vp, 'Left the company');
    $revoked = $this->recruiter->fresh();

    expect($revoked->roles)->toBeEmpty()
        ->and($revoked->revoked_roles)->toEqualCanonicalizing(['recruiter', 'manager'])
        ->and(AuditLog::query()->where('auditable_id', $revoked->id)->where('action', 'roles_removed')->exists())->toBeTrue();

    $this->access->restore($revoked, $this->vp, 'Rehired');

    expect($revoked->fresh()->access_status)->toBe(AccessState::Active)
        ->and($revoked->fresh()->getRoleNames()->all())->toBe(['employee']);
});

test('restoring from suspension brings the dormant roles back', function (): void {
    $this->access->suspend($this->recruiter, $this->vp, 'Leave');
    $this->access->restore($this->recruiter, $this->vp, 'Back from leave');

    expect($this->recruiter->fresh()->access_status)->toBe(AccessState::Active)
        ->and($this->recruiter->fresh()->can('pipeline.transition'))->toBeTrue();
});

test('every transition is audited with old and new state and repeated calls change nothing', function (): void {
    $this->access->revoke($this->recruiter, $this->vp, 'Left the company');
    $this->access->revoke($this->recruiter, $this->vp, 'Left the company');

    $rows = AuditLog::query()->where('auditable_id', $this->recruiter->id)->where('action', 'access_revoked')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->old_values)->toBe(['access_status' => 'active'])
        ->and($rows->first()->getAttribute('changes'))->toMatchArray(['access_status' => 'revoked', 'reason' => 'Left the company', 'source' => 'administrator'])
        ->and($rows->first()->user_id)->toBeNull();
});

test('access management needs users.access.manage, never applies to yourself and stays in your hierarchy', function (): void {
    $outsider = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');
    $manager = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->vpEmployee)->create()->id])->assignRole('manager');

    expect(fn () => $this->access->suspend($this->recruiter, $manager, 'x'))->toThrow(DomainException::class, 'users.access.manage')
        ->and(fn () => $this->access->suspend($this->vp, $this->vp, 'x'))->toThrow(DomainException::class, 'your own access')
        ->and(fn () => $this->access->suspend($outsider, $this->vp, 'x'))->toThrow(DomainException::class, 'outside your hierarchy')
        ->and($outsider->fresh()->access_status)->toBe(AccessState::Active);
});

test('a reason is required', function (): void {
    expect(fn () => $this->access->suspend($this->recruiter, $this->vp, '  '))->toThrow(DomainException::class, 'reason');
});

test('the last effective CHRO cannot be suspended or revoked, and the refused attempt is audited', function (): void {
    $vpWithAccess = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->access->revoke($vpWithAccess, null, 'Left', 'separation');

    expect(fn () => $this->access->revoke($this->chro, null, 'Left', 'separation'))->toThrow(LastChroProtectedException::class)
        ->and($this->chro->fresh()->access_status)->toBe(AccessState::Active)
        ->and($this->chro->fresh()->hasRole('chro'))->toBeTrue()
        ->and(AuditLog::query()->where('auditable_id', $this->chro->id)->where('action', 'last_chro_protected')->exists())->toBeTrue();
});

test('a second effective CHRO lets the first be suspended', function (): void {
    User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');

    $this->access->suspend($this->chro, null, 'Leave', 'employment');

    expect($this->chro->fresh()->access_status)->toBe(AccessState::Suspended);
});

test('the access state cannot be written outside the access service', function (): void {
    expect(fn () => $this->recruiter->forceFill(['access_status' => AccessState::Revoked])->save())->toThrow(LogicException::class, 'StaffAccessService');
});

test('transitions are announced after commit with ids only', function (): void {
    Event::fake([EmployeeAccessSuspended::class, EmployeeAccessRevoked::class, EmployeeAccessRestored::class]);

    DB::transaction(function (): void {
        $this->access->suspend($this->recruiter, $this->vp, 'Leave');
        Event::assertNotDispatched(EmployeeAccessSuspended::class);
    });

    Event::assertDispatched(EmployeeAccessSuspended::class, fn (EmployeeAccessSuspended $event) => $event->userId === $this->recruiter->id
        && $event->employeeId === $this->recruiterEmployee->id
        && $event->actorId === $this->vp->id);
});

test('suspending or revoking ends every stored session and the remember-me token', function (): void {
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([
        ['id' => 'a', 'user_id' => $this->recruiter->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'b', 'user_id' => $this->recruiter->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'c', 'user_id' => $this->vp->id, 'payload' => '', 'last_activity' => time()],
    ]);
    $token = $this->recruiter->getRememberToken();

    $this->access->suspend($this->recruiter, $this->vp, 'Leave');

    expect(DB::table('sessions')->where('user_id', $this->recruiter->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->vp->id)->count())->toBe(1)
        ->and($this->recruiter->fresh()->getRememberToken())->not->toBe($token)
        ->and($this->recruiter->fresh()->session_epoch)->toBe(1);
});

test('a session from before the revocation is signed out on its next panel request', function (): void {
    $this->actingAs($this->recruiter)->get('/admin/acme')->assertOk();

    $this->access->revoke($this->recruiter, $this->vp, 'Left the company');

    $this->get('/admin/acme')->assertRedirect(Filament::getLoginUrl());
    expect(auth()->check())->toBeFalse();
});

test('a session stamped before a sign-out-everywhere is rejected even for a still active login', function (): void {
    $this->actingAs($this->recruiter)->get('/admin/acme')->assertOk();

    app(SessionRevocationService::class)->revokeAll($this->recruiter, reason: 'sign_out_everywhere');

    $this->get('/admin/acme')->assertRedirect(Filament::getLoginUrl());
});

test('a suspended login is refused at sign-in', function (): void {
    $this->access->suspend($this->recruiter, $this->vp, 'Leave');

    Livewire::test(Login::class)
        ->fillForm(['email' => $this->recruiter->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(auth()->check())->toBeFalse();
});

test('signing in stamps the session epoch, the last sign-in time and a login audit row', function (): void {
    Livewire::test(Login::class)
        ->fillForm(['email' => $this->recruiter->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->id())->toBe($this->recruiter->id)
        ->and($this->recruiter->fresh()->last_login_at)->not->toBeNull()
        ->and(session(SessionRevocationService::SESSION_KEY))->toBe(0)
        ->and(AuditLog::query()->where('auditable_id', $this->recruiter->id)->where('action', 'login')->exists())->toBeTrue();
});
