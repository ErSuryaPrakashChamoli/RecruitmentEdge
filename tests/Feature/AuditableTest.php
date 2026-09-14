<?php

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('creating an audited model writes a created audit log', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $candidate = Candidate::factory()->create();

    $log = AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $candidate->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('created')
        ->and($log->user_id)->toBe($user->id);
});

test('updating an audited model writes an updated audit log excluding noise fields', function (): void {
    $candidate = Candidate::factory()->create(['full_name' => 'Original Name']);

    $candidate->update(['full_name' => 'New Name']);

    $log = AuditLog::query()
        ->where('auditable_type', Candidate::class)
        ->where('auditable_id', $candidate->id)
        ->where('action', 'updated')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->changes)->toHaveKey('full_name')
        ->and($log->changes)->not->toHaveKey('updated_at');
});

test('an updated audit log stores both the old and the new value of each changed field', function (): void {
    $candidate = Candidate::factory()->create(['full_name' => 'Original Name']);

    $candidate->update(['full_name' => 'New Name']);

    $log = AuditLog::query()
        ->where('auditable_type', Candidate::class)
        ->where('auditable_id', $candidate->id)
        ->where('action', 'updated')
        ->first();

    expect($log->old_values)->toBe(['full_name' => 'Original Name'])
        ->and($log->changes)->toBe(['full_name' => 'New Name'])
        ->and($log->diffRows())->toBe([['field' => 'full_name', 'old' => 'Original Name', 'new' => 'New Name']]);
});

test('a no-op update does not write an audit log', function (): void {
    $candidate = Candidate::factory()->create();

    AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $candidate->id)->delete();

    $candidate->update(['full_name' => $candidate->full_name]);

    expect(AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $candidate->id)->exists())->toBeFalse();
});

test('deleting an audited model writes a deleted audit log', function (): void {
    $candidate = Candidate::factory()->create();

    $candidate->delete();

    $log = AuditLog::query()
        ->where('auditable_type', Candidate::class)
        ->where('auditable_id', $candidate->id)
        ->where('action', 'deleted')
        ->first();

    expect($log)->not->toBeNull();
});

test('recruitment costs, settings, interviews and joinings are audited', function (): void {
    $cost = RecruitmentCost::factory()->create();
    $cost->update(['campaign' => 'Diwali Drive']);

    RecruitmentSetting::put('candidate_stall_days', 7, 'int');
    RecruitmentSetting::put('candidate_stall_days', 9, 'int');

    $interview = Interview::factory()->create();
    $joining = CandidateJoining::factory()->create();

    $costUpdate = AuditLog::query()->where('auditable_type', RecruitmentCost::class)->where('action', 'updated')->first();
    $settingUpdate = AuditLog::query()->where('auditable_type', RecruitmentSetting::class)->where('action', 'updated')->first();

    expect($costUpdate->changes)->toHaveKey('campaign', 'Diwali Drive')
        ->and($settingUpdate->old_values)->toHaveKey('value', '7')
        ->and($settingUpdate->changes)->toHaveKey('value', '9')
        ->and(AuditLog::query()->where('auditable_type', Interview::class)->where('auditable_id', $interview->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', CandidateJoining::class)->where('auditable_id', $joining->id)->exists())->toBeTrue();
});

test('changing role permissions through the Roles resource writes an audit log with old and new permission names', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('chro');
    $this->actingAs($admin);

    $role = Role::findOrCreate('audited-role', 'web');
    $role->syncPermissions(['audit.view']);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['permissions' => [Permission::findByName('reports.export', 'web')->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $log = AuditLog::query()
        ->where('auditable_type', Role::class)
        ->where('auditable_id', $role->id)
        ->where('action', 'permissions_updated')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->old_values)->toBe(['permissions' => ['audit.view']])
        ->and($log->changes)->toBe(['permissions' => ['reports.export']]);
});
