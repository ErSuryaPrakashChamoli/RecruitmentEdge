<?php

use App\Filament\Pages\AiCopilot;
use App\Models\AiConversation;
use App\Models\AiKnowledgeArticle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('recruiter');
    actingAs($this->user);
});

test('starting a new conversation creates and opens a fresh one', function (): void {
    $component = Livewire::test(AiCopilot::class);
    $first = $component->get('conversationId');

    $component->call('newConversation');

    expect($component->get('conversationId'))->not->toBe($first)
        ->and(AiConversation::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

test('a user can switch between their own recent conversations but never to someone else\'s', function (): void {
    $older = AiConversation::factory()->create(['user_id' => $this->user->id, 'title' => 'Older chat about notice periods', 'last_message_at' => now()->subDay()]);
    $foreign = AiConversation::factory()->create(['title' => 'Someone else chat']);

    Livewire::test(AiCopilot::class)
        ->call('newConversation')
        ->assertSee('Older chat about notice periods')
        ->assertDontSee('Someone else chat')
        ->call('switchConversation', $older->id)
        ->assertSet('conversationId', $older->id)
        ->call('switchConversation', $foreign->id)
        ->assertSet('conversationId', $older->id);
});

test('asking with no provider configured answers from the knowledge base instead of failing', function (): void {
    AiKnowledgeArticle::factory()->create(['title' => 'Referral Bonus Policy', 'content' => 'Referral bonuses are paid after 90 days.', 'is_published' => true]);

    $component = Livewire::test(AiCopilot::class)
        ->set('question', 'How does the referral bonus work?')
        ->call('ask');

    $conversation = AiConversation::query()->find($component->get('conversationId'));

    expect($conversation->title)->toBe('How does the referral bonus work?')
        ->and($conversation->messages()->where('role', 'assistant')->latest('id')->value('content'))
        ->toContain('Referral Bonus Policy')
        ->toContain('Full AI is not configured');
});

test('a tool call from another conversation cannot be approved through the page', function (): void {
    $approver = User::factory()->create();
    $approver->assignRole('chro');
    actingAs($approver);

    $foreignMessage = AiConversation::factory()->create()->messages()->create(['role' => 'assistant', 'content' => null]);
    $toolCall = $foreignMessage->toolCalls()->create([
        'tool_name' => 'reject_candidates',
        'provider_call_id' => 'call_foreign',
        'arguments' => ['application_ids' => [999999], 'rejection_reason_id' => 1],
        'risk_level' => 'high_impact',
        'status' => 'pending',
        'requires_confirmation' => true,
    ]);

    Livewire::test(AiCopilot::class)->call('approveToolCall', $toolCall->id);

    expect($toolCall->fresh()->status->value)->toBe('pending');
});
