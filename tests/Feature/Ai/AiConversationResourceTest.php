<?php

use App\Models\AiConversation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->owner = User::factory()->create();
    $this->conversation = AiConversation::factory()->create(['user_id' => $this->owner->id, 'title' => 'Stuck candidates review']);
    $this->conversation->messages()->create(['role' => 'user', 'content' => 'Which candidates are stuck?']);

    $assistant = $this->conversation->messages()->create(['role' => 'assistant', 'content' => null]);
    $call = $assistant->toolCalls()->create([
        'tool_name' => 'find_stuck_candidates',
        'provider_call_id' => 'call_stuck',
        'arguments' => ['days' => 14],
        'risk_level' => 'read',
        'status' => 'executed',
        'requires_confirmation' => false,
    ]);
    $call->result()->create(['output' => ['summary' => 'Found 3 application(s) stuck for 14+ days.'], 'success' => true]);
});

test('an ai.manage user can list conversations and review one\'s messages, tool calls, and results', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('chro');

    actingAs($admin)
        ->get('/admin/ai-conversations')
        ->assertSuccessful()
        ->assertSee('Stuck candidates review');

    actingAs($admin)
        ->get("/admin/ai-conversations/{$this->conversation->id}")
        ->assertSuccessful()
        ->assertSee('Which candidates are stuck?')
        ->assertSee('find_stuck_candidates')
        ->assertSee('"days": 14')
        ->assertSee('Found 3 application(s) stuck for 14+ days.');
});

test('users without ai.manage cannot open the conversation review screen, even for their own conversation', function (): void {
    $this->owner->assignRole('recruiter');

    actingAs($this->owner)->get('/admin/ai-conversations')->assertForbidden();
    actingAs($this->owner)->get("/admin/ai-conversations/{$this->conversation->id}")->assertForbidden();
});
