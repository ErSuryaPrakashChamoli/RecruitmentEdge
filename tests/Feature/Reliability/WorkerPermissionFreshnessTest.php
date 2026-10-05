<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 8.9 (P89-SEC-003, ED-11): a queue worker lives up to an hour. A role's permissions changed
 * in the panel (another process: database row + shared permission cache flushed) must apply from the
 * worker's next job, not from its next restart.
 */
test('a role permission changed elsewhere applies from the worker\'s next job', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');
    $permission = Permission::query()->where('name', 'settings.manage')->sole();

    // The worker has loaded the role → permission map while running an earlier job.
    expect($user->fresh()->can('settings.manage'))->toBeFalse();

    // The panel (another process) grants it: the row is written and the shared cache flushed —
    // nothing reaches this process's in-memory map.
    // SaaS-7: the map is cached per tenant; the other process rotates this tenant's generation.
    DB::table('role_has_permissions')->insert(['permission_id' => $permission->id, 'role_id' => Role::findByName('recruiter')->id]);
    Cache::store(config('permission.cache.store') === 'default' ? null : config('permission.cache.store'))->forever(app(PermissionRegistrar::class)->generationKey($this->tenant->id), 'rotated-by-another-process');

    expect($user->fresh()->can('settings.manage'))->toBeFalse();

    // A real payload: queued inside the tenant, it carries the tenant and its Context (SaaS-1).
    $job = new SyncJob(app(), json_encode(['job' => 'x', 'data' => [], 'tenant_id' => $this->tenant->id, 'illuminate:log:context' => Context::dehydrate()]), 'sync', 'default');
    event(new JobProcessing('sync', $job));

    expect($user->fresh()->can('settings.manage'))->toBeTrue()
        ->and(app(PermissionRegistrar::class))->toBe(app(PermissionRegistrar::class));

    event(new JobAttempted('sync', $job));
});
