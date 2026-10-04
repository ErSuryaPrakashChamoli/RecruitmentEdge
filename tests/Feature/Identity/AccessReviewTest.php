<?php

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Filament\Pages\AccessReview;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->teamMember = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->vpEmployee)->create()->id])->assignRole('recruiter');
    $this->outsider = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('recruiter');
});

test('the access review needs access.review', function (): void {
    actingAs($this->teamMember);
    get('/admin/acme/access-review')->assertForbidden();

    actingAs($this->vp);
    get('/admin/acme/access-review')->assertOk();
});

test('the access review is limited to the reviewer\'s hierarchy — no global bypass', function (): void {
    actingAs($this->vp);

    Livewire::test(AccessReview::class)
        ->assertCanSeeTableRecords([$this->vp, $this->teamMember])
        ->assertCanNotSeeTableRecords([$this->outsider, $this->chro]);

    actingAs($this->chro);

    Livewire::test(AccessReview::class)->assertCanSeeTableRecords([$this->outsider, $this->teamMember, $this->vp]);
});

test('the review shows employment, access, MFA and separation for each login', function (): void {
    lifecycleFixture(fn () => EmployeeSeparation::factory()->create(['employee_id' => $this->teamMember->employee_id, 'separation_date' => now()->addWeek()->toDateString()]));
    actingAs($this->vp);

    Livewire::test(AccessReview::class)
        ->assertTableColumnStateSet('access_status', AccessState::Active, $this->teamMember)
        ->assertTableColumnStateSet('mfa', 'Not configured', $this->teamMember)
        ->assertTableColumnStateSet('mfa', 'Required — pending', $this->vp)
        ->assertSee('(scheduled)');
});

test('rendering the review costs the same number of queries for 5 or 25 people', function (): void {
    actingAs($this->chro);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(AccessReview::class);

        return count(DB::getQueryLog());
    };

    User::factory()->count(5)->create()->each(fn (User $user) => $user->assignRole('recruiter'));
    $small = $count();
    User::factory()->count(20)->create()->each(fn (User $user) => $user->assignRole('recruiter'));
    $large = $count();

    expect($large - $small)->toBeLessThanOrEqual(2);
});

test('the access actions are confirmed, audited through the service, and never offered on yourself', function (): void {
    actingAs($this->vp);

    Livewire::test(AccessReview::class)
        ->callAction(TestAction::make('suspendAccess')->table($this->teamMember), data: ['reason' => 'Investigation']);

    expect($this->teamMember->fresh()->access_status)->toBe(AccessState::Suspended)
        ->and(AuditLog::query()->where('action', 'access_suspended')->where('auditable_id', $this->teamMember->id)->exists())->toBeTrue();

    actingAs($this->chro);
    Livewire::test(EditUser::class, ['record' => $this->vp->getRouteKey()])->assertActionVisible('revokeAccess');
    Livewire::test(AccessReview::class)->assertActionHidden(TestAction::make('revokeAccess')->table($this->chro));
});

test('identity:audit reports a login whose employment has ended, and writes nothing', function (): void {
    lifecycleFixture(fn () => Employee::query()->whereKey($this->teamMember->employee_id)->first()->update(['status' => EmployeeStatus::Inactive]));
    DB::enableQueryLog();

    $this->artisan('identity:audit')->expectsOutputToContain('active_login_without_current_employment')->assertFailed();

    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1);
    expect($writes)->toBeEmpty();
});

test('identity:reconcile-access is a dry run unless told to execute; then it maps access to employment', function (): void {
    lifecycleFixture(fn () => Employee::query()->whereKey($this->teamMember->employee_id)->first()->update(['status' => EmployeeStatus::Inactive]));
    lifecycleFixture(fn () => EmployeeSeparation::factory()->create(['employee_id' => $this->outsider->employee_id, 'separation_date' => now()->subDays(10)->toDateString()]));

    $this->artisan('identity:reconcile-access')->expectsOutputToContain('DRY RUN')->assertSuccessful();

    expect($this->teamMember->fresh()->access_status)->toBe(AccessState::Active)
        ->and($this->outsider->fresh()->access_status)->toBe(AccessState::Active);

    $this->artisan('identity:reconcile-access --execute')->assertSuccessful();
    $this->artisan('identity:reconcile-access --execute')->assertSuccessful();

    expect($this->teamMember->fresh()->access_status)->toBe(AccessState::Suspended)
        ->and($this->outsider->fresh()->access_status)->toBe(AccessState::Revoked)
        ->and($this->outsider->employee->fresh()->status)->toBe(EmployeeStatus::Separated)
        ->and($this->chro->fresh()->access_status)->toBe(AccessState::Active);
});
