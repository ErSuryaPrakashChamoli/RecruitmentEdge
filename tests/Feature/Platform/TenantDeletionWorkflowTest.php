<?php

use App\Enums\DeletionRequestStatus;
use App\Enums\TenantStatus;
use App\Filament\Platform\Pages\DeletionRequests;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\Employee;
use App\Models\PlatformEvent;
use App\Models\Tenant;
use App\Models\TenantDeletionRequest;
use App\Models\User;
use App\Services\Platform\TenantAdministrationService;
use App\Services\Platform\TenantDeletionService;
use App\Services\Platform\TenantPurgePlan;
use App\Services\Platform\TenantPurgeService;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantQueueGuard;
use App\Services\Tenancy\TenantSchema;
use App\Services\Tenancy\TenantUnavailable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Platform\PlatformWorld;

/*
 * SaaS-5: deleting a tenant is request → second-operator approval → deletion pending with a grace
 * period (cancellable) → purge → deleted. The purge deletes only that tenant's rows and files,
 * children before parents, in chunks, resumably, once; it keeps the tenant row, the retained
 * records (billing, audit, support access, plan history) and every other tenant's data.
 */
beforeEach(function (): void {
    $this->world = PlatformWorld::build($this->tenant);
    $this->deletions = app(TenantDeletionService::class);
    $this->doomed = Tenant::factory()->create(['slug' => 'doomed', 'name' => 'Doomed Ltd']);
    $this->member = deletionPopulate($this->doomed);
    $this->doomedUserId = $this->member->id;
    app(TenantAdministrationService::class)->cancel($this->doomed, 'Customer left', $this->world->administrator);
});

/**
 * A tenant with members, roles, recruitment records, audit entries and files (tenant-prefixed and
 * a legacy path), and a queued job.
 */
function deletionPopulate(Tenant $tenant): User
{
    return TenantContext::current()->run($tenant, function () use ($tenant): User {
        (new RolePermissionSeeder)->run();
        $member = User::factory()->create(['email' => 'member@doomed.test', 'employee_id' => Employee::factory()->create(['photo_path' => "tenants/{$tenant->id}/employee-photos/me.jpg"])->id])->assignRole('chro');
        CandidateApplication::factory()->count(3)->create();
        $candidate = Candidate::factory()->create(['resume_path' => "resumes/legacy-{$tenant->id}.pdf"]);
        CandidateDocument::factory()->forCandidate($candidate)->create(['file_path' => "tenants/{$tenant->id}/candidate-documents/passport.pdf"]);

        Storage::disk('local')->put("resumes/legacy-{$tenant->id}.pdf", 'legacy resume');
        Storage::disk('local')->put("tenants/{$tenant->id}/candidate-documents/passport.pdf", 'passport');
        Storage::disk('local')->put("tenants/{$tenant->id}/employee-photos/me.jpg", 'photo');
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{"displayName":"X","tenant_id":'.$tenant->id.',"data":{}}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        // A pre-SaaS-1 Filament export (filament_exports/{id}/, no tenant prefix).
        $export = DB::table('exports')->insertGetId(['tenant_id' => $tenant->id, 'file_disk' => 'local', 'exporter' => 'X', 'total_rows' => 1, 'user_id' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put("filament_exports/{$export}/0000000000000001.csv", 'a,b');

        return $member;
    });
}

/**
 * Rows per table that carries a tenant id, for one tenant.
 *
 * @return array<string, int>
 */
function deletionRowCounts(int $tenantId): array
{
    return collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table): bool => ! str_starts_with($table, 'sqlite_') && ! in_array($table, TenantSchema::PLATFORM_TABLES, true) && Schema::hasColumn($table, 'tenant_id'))
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->where('tenant_id', $tenantId)->count()])
        ->all();
}

function deletionApprovedAndDue(PlatformWorld $world, Tenant $tenant): TenantDeletionRequest
{
    $request = app(TenantDeletionService::class)->request($tenant, 'Contract ended; data retention period over', $world->administrator);
    app(TenantDeletionService::class)->approve($request, $world->compliance);
    test()->travel(31)->days();

    return $request->fresh();
}

test('a deletion needs a cancelled tenant, a reason and a second operator; approval starts the grace period', function (): void {
    expect(fn () => $this->deletions->request($this->tenant, 'x', $this->world->administrator))->toThrow(DomainException::class, 'Only a cancelled tenant')
        ->and(fn () => $this->deletions->request($this->doomed, ' ', $this->world->administrator))->toThrow(DomainException::class, 'reason')
        ->and(fn () => $this->deletions->request($this->doomed, 'x', $this->world->support))->toThrow(DomainException::class, 'platform.deletion.manage');

    $request = $this->deletions->request($this->doomed, 'Contract ended', $this->world->administrator);

    expect($this->deletions->request($this->doomed, 'Again', $this->world->compliance)->is($request))->toBeTrue()
        ->and(fn () => $this->deletions->approve($request, $this->world->administrator))->toThrow(DomainException::class, 'different operator');

    $approved = $this->deletions->approve($request, $this->world->compliance);

    expect($approved->status)->toBe(DeletionRequestStatus::Approved)
        ->and($approved->purge_after->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and($this->doomed->fresh()->status)->toBe(TenantStatus::DeletionPending)
        ->and(fn () => $this->deletions->purgeNow($approved, $this->world->administrator))->toThrow(DomainException::class, 'grace period')
        ->and(app(TenantPurgeService::class)->purge($approved->id))->toBe('not due')
        ->and(TenantContext::current()->run($this->doomed, fn () => AuditLog::query()->whereIn('action', ['tenant_deletion_requested', 'tenant_deletion_approved', 'tenant_deletion_pending'])->orderBy('id')->pluck('user_id', 'action')->all()))
        ->toBe(['tenant_deletion_requested' => $this->world->administrator->id, 'tenant_deletion_pending' => $this->world->compliance->id, 'tenant_deletion_approved' => $this->world->compliance->id]);
});

test('a deletion is cancelled during its grace period: the tenant is cancelled again, its data intact', function (): void {
    $before = deletionRowCounts($this->doomed->id);
    $request = $this->deletions->request($this->doomed, 'Contract ended', $this->world->administrator);
    $this->deletions->approve($request, $this->world->compliance);

    $cancelled = $this->deletions->cancel($request, 'Customer renewed', $this->world->administrator);

    expect($cancelled->status)->toBe(DeletionRequestStatus::Cancelled)
        ->and($cancelled->is_open)->toBeNull()
        ->and($this->doomed->fresh()->status)->toBe(TenantStatus::Cancelled)
        ->and(app(TenantPurgeService::class)->purge($request->id))->toBe('nothing to purge (cancelled)')
        ->and(collect(deletionRowCounts($this->doomed->id))->except('audit_logs')->all())->toBe(collect($before)->except('audit_logs')->all())
        ->and($this->deletions->request($this->doomed, 'Ended for good', $this->world->administrator)->id)->not->toBe($request->id);
});

test('after the grace period the purge removes the tenant\'s rows and files only, keeps the retained records and marks it deleted', function (): void {
    $acmeBefore = deletionRowCounts($this->tenant->id);
    $betaBefore = deletionRowCounts($this->world->identity->beta->id);
    $retained = (array) config('platform.deletion.retain_tables');
    $request = deletionApprovedAndDue($this->world, $this->doomed);
    $retainedBefore = collect(deletionRowCounts($this->doomed->id))->only($retained)->all();
    Storage::disk('local')->put('resumes/someone-else.pdf', 'another tenant\'s legacy file');

    $this->deletions->purgeNow($request, $this->world->administrator);

    $after = deletionRowCounts($this->doomed->id);
    $request->refresh();

    expect($request->status)->toBe(DeletionRequestStatus::Purged)
        ->and($request->is_open)->toBeNull()
        ->and($this->doomed->fresh()->status)->toBe(TenantStatus::Deleted)
        ->and(collect($after)->except([...$retained, 'audit_logs'])->filter()->all())->toBe([])
        ->and(collect($after)->only(array_diff($retained, ['audit_logs']))->all())->toBe(collect($retainedBefore)->except('audit_logs')->all())
        ->and($after['audit_logs'])->toBeGreaterThan($retainedBefore['audit_logs'] ?? 0)
        ->and(deletionRowCounts($this->tenant->id))->toBe(collect($acmeBefore)->merge(['audit_logs' => deletionRowCounts($this->tenant->id)['audit_logs']])->all())
        ->and(deletionRowCounts($this->world->identity->beta->id))->toBe($betaBefore)
        ->and(User::query()->find($this->doomedUserId))->not->toBeNull()
        ->and(DB::table('tenant_memberships')->where('tenant_id', $this->doomed->id)->count())->toBe(0)
        ->and(DB::table('roles')->where('tenant_id', $this->doomed->id)->count())->toBe(0)
        ->and(DB::table('jobs')->where('payload', 'like', '%"tenant_id":'.$this->doomed->id.',%')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("tenants/{$this->doomed->id}"))->toBe([])
        ->and(Storage::disk('local')->exists("resumes/legacy-{$this->doomed->id}.pdf"))->toBeFalse()
        ->and(Storage::disk('local')->exists('resumes/someone-else.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('filament_exports'))->toBe([])
        ->and($request->progress['files_removed'])->toBe(4)
        ->and(PlatformEvent::query()->where('type', 'purge.completed')->count())->toBe(1);

    $completed = TenantContext::current()->run($this->doomed, fn () => AuditLog::query()->where('action', 'tenant_purge_completed')->sole());
    expect($completed->tenant_id)->toBe($this->doomed->id);
});

test('a purge runs once: a second start finds it purged, a live lease keeps a second worker out', function (): void {
    $request = deletionApprovedAndDue($this->world, $this->doomed);
    $request->forceFill(['status' => DeletionRequestStatus::Purging, 'lease_owner' => 'worker-a', 'lease_until' => now()->addMinutes(10)])->save();

    expect(app(TenantPurgeService::class)->purge($request->id, 'worker-b'))->toBe('already running')
        ->and(DB::table('candidates')->where('tenant_id', $this->doomed->id)->count())->toBeGreaterThan(0);

    $this->travel(11)->minutes();

    expect(app(TenantPurgeService::class)->purge($request->id, 'worker-b'))->toBe('purged')
        ->and(app(TenantPurgeService::class)->purge($request->id, 'worker-c'))->toBe('nothing to purge (purged)')
        ->and(TenantContext::current()->run($this->doomed, fn () => AuditLog::query()->where('action', 'tenant_purge_resumed')->count()))->toBe(1);
});

test('a failed purge is recorded, never shown as done, and resumes where it stopped', function (): void {
    $request = deletionApprovedAndDue($this->world, $this->doomed);
    // A retained table that depends on a purged one makes the plan refuse to run.
    config(['platform.deletion.retain_tables' => [...config('platform.deletion.retain_tables'), 'candidate_documents']]);

    expect(app(TenantPurgeService::class)->purge($request->id))->toBe('failed');

    $request->refresh();
    expect($request->status)->toBe(DeletionRequestStatus::Failed)
        ->and($request->last_error)->toContain('Purge plan refused')
        ->and($request->progress)->toBeNull()
        ->and(Storage::disk('local')->exists("tenants/{$this->doomed->id}/candidate-documents/passport.pdf"))->toBeTrue()
        ->and(DB::table('jobs')->where('payload', 'like', '%"tenant_id":'.$this->doomed->id.',%')->count())->toBe(1)
        ->and($this->doomed->fresh()->status)->toBe(TenantStatus::DeletionPending)
        ->and(PlatformEvent::query()->where('type', 'purge.failed')->sole()->severity->value)->toBe('critical')
        ->and(TenantContext::current()->run($this->doomed, fn () => AuditLog::query()->where('action', 'tenant_purge_failed')->count()))->toBe(1);

    config(['platform.deletion.retain_tables' => array_values(array_diff(config('platform.deletion.retain_tables'), ['candidate_documents']))]);
    $this->artisan('platform:sweep')->assertSuccessful();

    expect($request->fresh()->status)->toBe(DeletionRequestStatus::Purged)
        ->and($request->fresh()->attempts)->toBe(2)
        ->and($this->doomed->fresh()->status)->toBe(TenantStatus::Deleted);
});

test('the purge plan covers every tenant table except the retained ones, children before parents', function (): void {
    $plan = TenantPurgePlan::build();
    $position = array_flip($plan->tables);

    expect(array_diff(TenantSchema::TENANT_TABLES, $plan->tables, $plan->retained))->toBe([])
        ->and(array_intersect($plan->tables, (array) config('platform.deletion.retain_tables')))->toBe([])
        ->and($plan->tables)->toContain('tenant_memberships', 'model_has_roles', 'roles')
        ->and($plan->tables)->not->toContain('tenants', 'users', 'audit_logs', 'tenant_deletion_requests');

    foreach ($plan->tables as $table) {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $parent = $foreignKey['foreign_table'];

            if ($parent !== $table && isset($position[$parent]) && ! in_array($foreignKey['columns'][0], $plan->nullFirst[$table] ?? [], true)) {
                expect($position[$table])->toBeLessThan($position[$parent], "{$table} must be purged before {$parent}");
            }
        }
    }
});

test('deletion is a workflow on the console and the platform panel too; the lifecycle command no longer skips it', function (): void {
    $this->artisan('tenants:lifecycle doomed deleted --reason="Shortcut"')->expectsOutputToContain('workflow')->assertFailed();
    $this->artisan('tenants:deletion request doomed --operator=admin@platform.test --reason="Contract ended"')->assertSuccessful();
    $this->artisan('tenants:deletion status doomed')->expectsOutputToContain('requested')->assertSuccessful();

    $request = TenantDeletionRequest::query()->where('tenant_id', $this->doomed->id)->sole();
    Filament::setCurrentPanel('platform');
    $this->actingAs($this->world->administrator);

    Livewire::test(DeletionRequests::class)->callTableAction('approve', $request)->assertNotified('Not done');

    $this->actingAs($this->world->secondAdministrator);
    Livewire::test(DeletionRequests::class)->callTableAction('approve', $request)->assertNotified('Deletion approved');

    expect($request->fresh()->status)->toBe(DeletionRequestStatus::Approved)
        ->and($this->doomed->fresh()->status)->toBe(TenantStatus::DeletionPending);

    $this->artisan('tenants:deletion cancel doomed --operator=admin@platform.test --reason="Renewed"')->assertSuccessful();
    expect($this->doomed->fresh()->status)->toBe(TenantStatus::Cancelled);
});

test('the purge leaves no stale cache entry and no runnable queued work of the tenant', function (): void {
    config(['cache.default' => 'database']);
    $request = deletionApprovedAndDue($this->world, $this->doomed);
    Cache::put(TenantCache::key('billing:status:v1', $this->doomed->id), ['plan' => 'growth'], 600);
    Cache::lock(TenantCache::key('alert-lock', $this->doomed->id), 60)->get();
    Cache::put(TenantCache::key('billing:status:v1', $this->tenant->id), ['plan' => 'legacy'], 600);
    Cache::put(TenantCache::key('billing:status:v1', $this->doomed->id * 10), ['plan' => 'other'], 600);

    $this->deletions->purgeNow($request, $this->world->administrator);

    expect(Cache::get(TenantCache::key('billing:status:v1', $this->doomed->id)))->toBeNull()
        ->and(DB::table('cache_locks')->where('key', 'like', '%t:'.$this->doomed->id.':%')->count())->toBe(0)
        ->and(Cache::get(TenantCache::key('billing:status:v1', $this->tenant->id)))->toBe(['plan' => 'legacy'])
        ->and(Cache::get(TenantCache::key('billing:status:v1', $this->doomed->id * 10)))->toBe(['plan' => 'other']);

    // A job of the tenant queued before the purge (or restored later) is refused by the queue guard.
    $job = Mockery::mock(Job::class);
    $job->allows('payload')->andReturn(['tenant_id' => $this->doomed->id]);

    expect(fn () => TenantContext::current()->run($this->doomed->fresh(), fn () => app(TenantQueueGuard::class)->check($job)))->toThrow(TenantUnavailable::class, 'deleted');
});

test('a purge that keeps failing waits for an operator, whose retry gives it a new budget; resumed runs are not failures', function (): void {
    $request = deletionApprovedAndDue($this->world, $this->doomed);
    TenantDeletionRequest::query()->whereKey($request->id)->update(['status' => DeletionRequestStatus::Failed->value, 'failures' => TenantPurgeService::MAX_FAILURES, 'attempts' => 7]);

    $this->artisan('platform:sweep')->assertSuccessful();

    expect($request->fresh()->status)->toBe(DeletionRequestStatus::Failed)
        ->and(app(TenantPurgeService::class)->purge($request->id))->toBe('failures exhausted')
        ->and(PlatformEvent::query()->where('type', 'purge.exhausted')->count())->toBe(1);

    $this->deletions->purgeNow($request->fresh(), $this->world->administrator);

    expect($request->fresh())->status->toBe(DeletionRequestStatus::Purged)->failures->toBe(0)
        ->and(TenantContext::current()->run($this->doomed, fn () => AuditLog::query()->where('action', 'tenant_purge_requested')->sole()->changes['failures_reset']))->toBe(TenantPurgeService::MAX_FAILURES);
});

test('a pending deletion is withdrawn only through the workflow, and the sweep queues purges only for pending tenants', function (): void {
    $request = $this->deletions->approve($this->deletions->request($this->doomed, 'Contract ended', $this->world->administrator), $this->world->compliance);

    $this->artisan('tenants:lifecycle doomed cancel --reason="Shortcut"')->expectsOutputToContain('deletion workflow')->assertFailed();

    expect($this->doomed->fresh()->status)->toBe(TenantStatus::DeletionPending);

    // A request left approved while its tenant is no longer pending is never queued.
    Tenant::query()->whereKey($this->doomed->id)->update(['status' => TenantStatus::Cancelled->value]);
    TenantDeletionRequest::query()->whereKey($request->id)->update(['purge_after' => now()->subDay()]);

    expect($this->deletions->dispatchDue())->toBe(0)
        ->and($request->fresh()->status)->toBe(DeletionRequestStatus::Approved);
});
