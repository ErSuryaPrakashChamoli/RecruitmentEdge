<?php

use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Tenant;
use App\Services\Tenancy\CrossTenantViolation;
use App\Services\Tenancy\MissingTenantContext;
use App\Services\Tenancy\TenancyVerifier;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantSchema;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * SaaS-1: the tenant boundary at the model and database level — the layer every panel page,
 * service, job and AI tool stands on. A second tenant is created beside the test's own (acme).
 */
beforeEach(function (): void {
    $this->other = Tenant::factory()->create(['slug' => 'globex', 'name' => 'Globex Hiring']);
});

test('a tenant-owned query without a tenant is refused, never widened to every tenant', function (): void {
    Candidate::factory()->create();

    expect(fn () => TenantContext::current()->runWithoutTenant(fn () => Candidate::query()->count()))
        ->toThrow(MissingTenantContext::class);
});

test('a new row takes the current tenant, and a row built for another tenant is refused', function (): void {
    $department = Department::factory()->create();

    expect($department->tenant_id)->toBe($this->tenant->id);

    $foreign = Department::factory()->make();
    $foreign->tenant_id = $this->other->id;

    expect(fn () => $foreign->save())->toThrow(CrossTenantViolation::class)
        ->and(Department::query()->withoutTenancy()->where('tenant_id', $this->other->id)->exists())->toBeFalse();
});

test('a row can never be moved to another tenant', function (): void {
    $department = Department::factory()->create();
    $department->tenant_id = $this->other->id;

    expect(fn () => $department->save())->toThrow(CrossTenantViolation::class)
        ->and($department->fresh()->tenant_id)->toBe($this->tenant->id);
});

test('another tenant\'s row is invisible: lists, counts, finds and updates stay in the tenant', function (): void {
    $own = Candidate::factory()->create(['full_name' => 'Own Candidate']);
    $foreign = TenantContext::current()->run($this->other, fn () => Candidate::factory()->create(['full_name' => 'Foreign Candidate']));

    expect(Candidate::query()->pluck('id')->all())->toBe([$own->id])
        ->and(Candidate::query()->find($foreign->id))->toBeNull()
        ->and(Candidate::query()->whereKey($foreign->id)->update(['full_name' => 'Changed']))->toBe(0)
        ->and(Candidate::query()->whereKey($foreign->id)->delete())->toBe(0)
        ->and(TenantContext::current()->run($this->other, fn () => Candidate::query()->find($foreign->id)->full_name))->toBe('Foreign Candidate');
});

test('a reference the database cannot check (ON DELETE SET NULL) is refused across tenants by the model', function (): void {
    $foreignDepartment = TenantContext::current()->run($this->other, fn () => Department::factory()->create());

    expect(fn () => Designation::factory()->create(['department_id' => $foreignDepartment->id]))
        ->toThrow(CrossTenantViolation::class);
});

test('the database itself refuses a cross-tenant reference, even from a raw insert', function (): void {
    $foreignCandidate = TenantContext::current()->run($this->other, fn () => Candidate::factory()->create());
    $ownApplication = CandidateApplication::factory()->create();

    expect(fn () => DB::table('candidate_applications')->insert([
        'tenant_id' => $this->tenant->id,
        'application_code' => 'APP-RAW-1',
        'candidate_id' => $foreignCandidate->id,
        'requisition_id' => $ownApplication->requisition_id,
        'current_stage' => 'sourced',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a raw insert that names no tenant is refused by the database', function (): void {
    expect(fn () => DB::table('departments')->insert(['name' => 'Nameless', 'code' => 'NONE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
});

test('business codes are unique per tenant: two tenants may share a code, one tenant may not repeat it', function (): void {
    Department::factory()->create(['code' => 'ENG']);
    TenantContext::current()->run($this->other, fn () => Department::factory()->create(['code' => 'ENG']));

    expect(Department::query()->withoutTenancy()->where('code', 'ENG')->count())->toBe(2)
        ->and(fn () => Department::factory()->create(['code' => 'ENG']))->toThrow(UniqueConstraintViolationException::class);
});

test('crossing tenants is always explicit and names itself', function (): void {
    Candidate::factory()->create();
    TenantContext::current()->run($this->other, fn () => Candidate::factory()->create());

    expect(Candidate::query()->count())->toBe(1)
        ->and(Candidate::query()->withoutTenancy()->count())->toBe(2);
});

test('running in another tenant restores the previous tenant afterwards, even after a failure', function (): void {
    expect(fn () => TenantContext::current()->run($this->other, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class)
        ->and(TenantContext::current()->id())->toBe($this->tenant->id)
        ->and(TenantContext::current()->run($this->other, fn () => TenantContext::current()->id()))->toBe($this->other->id)
        ->and(TenantContext::current()->id())->toBe($this->tenant->id);
});

test('every table is classified, and every tenant-owned table has a mandatory tenant_id', function (): void {
    $schema = DB::getDriverName() === 'sqlite' ? 'main' : DB::getDatabaseName();
    $tables = collect(Schema::getTableListing($schema, schemaQualified: false))->reject(fn (string $table) => str_starts_with($table, 'sqlite_'));

    expect($tables->diff(TenantSchema::classifiedTables())->values()->all())->toBe([]);

    foreach (TenantSchema::TENANT_TABLES as $table) {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'tenant_id');

        expect($column)->not->toBeNull("{$table} has no tenant_id")
            ->and($column['nullable'])->toBeFalse("{$table}.tenant_id is nullable");
    }
});

test('the verifier finds a clean database clean, and a cross-tenant reference written behind the model\'s back', function (): void {
    $designation = Designation::factory()->create();
    $foreignDepartment = TenantContext::current()->run($this->other, fn () => Department::factory()->create());

    expect(app(TenancyVerifier::class)->violations())->toBe([]);

    DB::table('designations')->where('id', $designation->id)->update(['department_id' => $foreignDepartment->id]);

    expect(app(TenancyVerifier::class)->violations())->toBe(['cross-tenant reference: designations.department_id → departments' => 1])
        ->and($this->artisan('tenancy:verify')->run())->toBe(1);
});
