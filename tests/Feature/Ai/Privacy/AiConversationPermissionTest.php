<?php

use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->vpEmployee = Employee::factory()->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->teamUser = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->vpEmployee)->create()->id])->assignRole('recruiter');
    $this->outsider = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');
    $this->teamConversation = AiConversation::factory()->create(['user_id' => $this->teamUser->id, 'title' => 'Team conversation']);
    $this->outsideConversation = AiConversation::factory()->create(['user_id' => $this->outsider->id, 'title' => 'Outside conversation']);
});

test('reviewing conversations needs ai.conversations.view and is limited to the reviewer\'s hierarchy', function (): void {
    actingAs($this->vp);

    get('/admin/ai-conversations')->assertOk()->assertSee('Team conversation')->assertDontSee('Outside conversation');
    get("/admin/ai-conversations/{$this->teamConversation->id}")->assertOk();
    get("/admin/ai-conversations/{$this->outsideConversation->id}")->assertNotFound();
});

test('view-all reviewers see every conversation', function (): void {
    actingAs(User::factory()->create()->assignRole('chro'));

    get('/admin/ai-conversations')->assertOk()->assertSee('Team conversation')->assertSee('Outside conversation');
});

test('ai.manage and audit.view alone grant no access to anyone\'s conversations', function (string $permission): void {
    $role = Role::findOrCreate("only-{$permission}");
    $role->givePermissionTo($permission);
    $manager = Employee::factory()->create();
    lifecycleFixture(fn () => $this->vpEmployee->update(['reports_to_id' => $manager->id]));
    $user = User::factory()->create(['employee_id' => $manager->id])->assignRole($role);
    actingAs($user);

    get('/admin/ai-conversations')->assertForbidden();
    get("/admin/ai-conversations/{$this->teamConversation->id}")->assertNotFound();
    get('/admin/ai-action-logs')->assertForbidden();
    expect($user->can('view', $this->teamConversation))->toBeFalse();
})->with(['ai.manage', 'audit.view']);

test('owners keep access to their own conversation, and nobody else can delete it', function (): void {
    expect($this->teamUser->can('view', $this->teamConversation))->toBeTrue()
        ->and($this->teamUser->can('view', $this->outsideConversation))->toBeFalse()
        ->and($this->vp->can('delete', $this->teamConversation))->toBeFalse();
});

test('AI action logs follow the same hierarchy rule', function (): void {
    $teamLog = AiActionLog::query()->create(['user_id' => $this->teamUser->id, 'tool_name' => 'team_tool_marker', 'risk_level' => 'read', 'status' => 'executed']);
    $outsideLog = AiActionLog::query()->create(['user_id' => $this->outsider->id, 'tool_name' => 'outside_tool_marker', 'risk_level' => 'read', 'status' => 'executed']);
    actingAs($this->vp);

    get('/admin/ai-action-logs')->assertOk()->assertSee('team_tool_marker')->assertDontSee('outside_tool_marker');

    expect($this->vp->can('view', $teamLog))->toBeTrue()
        ->and($this->vp->can('view', $outsideLog))->toBeFalse();
});
