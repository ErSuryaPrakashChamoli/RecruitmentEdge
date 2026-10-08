<?php

use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Enums\TenantStatus;
use App\Models\CommunicationTemplate;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ProductionBaselineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

test('a missing default role and permission are created with their defaults', function (): void {
    $this->seed(RolePermissionSeeder::class);
    Role::byKeyOrFail('recruiter')->delete();
    Permission::findByName('billing.view')->delete();

    $this->seed(ProductionBaselineSeeder::class);

    $recruiter = Role::byKeyOrFail('recruiter');
    expect($recruiter->hasPermissionTo('candidates.create'))->toBeTrue()
        ->and($recruiter->hasPermissionTo('incentives.approve'))->toBeFalse()
        ->and($recruiter->is_protected)->toBeFalse();
    expect(Role::byKeyOrFail('chro')->hasPermissionTo('billing.view'))->toBeTrue();
});

test('an existing role keeps the permissions administrators gave it while CHRO gets what it lacks', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $manager = Role::byKeyOrFail('manager');
    $manager->revokePermissionTo('reports.export');
    $manager->givePermissionTo(Permission::findOrCreate('custom.permission'));
    Role::byKeyOrFail('chro')->revokePermissionTo('audit.view');

    $this->seed(ProductionBaselineSeeder::class);

    expect($manager->fresh()->hasPermissionTo('reports.export'))->toBeFalse()
        ->and($manager->fresh()->hasPermissionTo('custom.permission'))->toBeTrue();
    expect(Role::byKeyOrFail('chro')->hasPermissionTo('audit.view'))->toBeTrue();
});

test('a role created from administration with a default role name is left unclaimed', function (): void {
    $this->seed(RolePermissionSeeder::class);
    Role::byKeyOrFail('recruiter')->delete();
    $custom = Role::findOrCreate('recruiter');
    $custom->givePermissionTo('candidates.viewAny');

    $this->seed(ProductionBaselineSeeder::class);

    expect($custom->fresh()->key)->toBeNull()
        ->and($custom->fresh()->permissions->pluck('name')->all())->toBe(['candidates.viewAny']);
    expect(Role::byKey('recruiter'))->toBeNull();
});

test('starter email templates are created as drafts so no candidate is emailed automatically', function (): void {
    $this->seed(ProductionBaselineSeeder::class);

    expect(CommunicationTemplate::query()->where('channel', CommunicationChannel::Email)->pluck('status')->unique()->values()->all())
        ->toBe([TemplateStatus::Draft]);
});

test('hierarchy links that disagree with employees managers are repaired', function (): void {
    $chro = Employee::factory()->create();
    $vp = Employee::factory()->reportingTo($chro)->create();
    $recruiter = Employee::factory()->reportingTo($vp)->create();
    $outsider = Employee::factory()->create();
    DB::table('employee_hierarchy')->where('ancestor_id', $chro->id)->where('descendant_id', $recruiter->id)->delete();
    DB::table('employee_hierarchy')->where('ancestor_id', $vp->id)->where('descendant_id', $recruiter->id)->update(['depth' => 5]);
    DB::table('employee_hierarchy')->insert(['tenant_id' => $this->tenant->id, 'ancestor_id' => $outsider->id, 'descendant_id' => $recruiter->id, 'depth' => 1, 'created_at' => now()]);

    $this->seed(ProductionBaselineSeeder::class);

    expect(DB::table('employee_hierarchy')->where('tenant_id', $this->tenant->id)->orderBy('ancestor_id')->orderBy('descendant_id')->get()
        ->map(fn (object $row): array => [$row->ancestor_id, $row->descendant_id, $row->depth])->all())
        ->toEqual([
            [$chro->id, $chro->id, 0],
            [$chro->id, $vp->id, 1],
            [$chro->id, $recruiter->id, 2],
            [$vp->id, $vp->id, 0],
            [$vp->id, $recruiter->id, 1],
            [$recruiter->id, $recruiter->id, 0],
            [$outsider->id, $outsider->id, 0],
        ]);
});

test('the hierarchy is left unchanged when a reporting line loops back on itself', function (): void {
    $manager = Employee::factory()->create();
    $report = Employee::factory()->reportingTo($manager)->create();
    DB::table('employees')->where('id', $manager->id)->update(['reports_to_id' => $report->id]);

    $this->seed(ProductionBaselineSeeder::class);

    expect(DB::table('employee_hierarchy')->where('tenant_id', $this->tenant->id)->count())->toBe(3);
});

test('a tenant that is being deleted is skipped', function (): void {
    $leaving = Tenant::factory()->status(TenantStatus::DeletionPending)->create();

    $this->seed(ProductionBaselineSeeder::class);

    expect(TenantContext::current()->run($leaving, fn (): int => Role::query()->forCurrentTenant()->count()))->toBe(0);
});

test('running it again changes nothing', function (): void {
    $this->seed(ProductionBaselineSeeder::class);
    $countRows = fn (): array => collect(['permissions', 'roles', 'role_has_permissions', 'communication_templates', 'candidate_sources', 'recruitment_pipeline_templates'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
        ->all();
    $before = $countRows();

    $this->seed(ProductionBaselineSeeder::class);

    expect($countRows())->toBe($before);
});
