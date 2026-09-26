<?php

use App\Filament\Pages\AiCopilot;
use App\Models\AiConversation;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Orchestrator\AiOrchestrator;
use App\Services\AI\Orchestrator\ConversationContextBuilder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->recruiter = Employee::factory()->create();
    $this->user = User::factory()->create(['name' => 'PRIVATE-USER-DAVE', 'employee_id' => $this->recruiter->id])->assignRole('recruiter');
    $this->mine = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    $this->mine->candidate->update(['full_name' => 'PRIVATE-CANDIDATE-ALICE']);
    $this->theirs = CandidateApplication::factory()->create();
    $this->theirs->candidate->update(['full_name' => 'PRIVATE-CANDIDATE-OTHER', 'email' => 'other@example.invalid']);
});

/**
 * @param  array<int, LlmMessage>  $messages
 */
function conversationPrivacyText(array $messages): string
{
    return (string) json_encode(array_map(fn (LlmMessage $m) => $m->content, $messages));
}

test('the system prompt carries roles and codes, never the user\'s or the candidate\'s name', function (): void {
    $conversation = AiConversation::factory()->create(['user_id' => $this->user->id, 'context_type' => 'candidate', 'context_id' => $this->mine->candidate_id]);

    $prompt = conversationPrivacyText(app(ConversationContextBuilder::class)->build($conversation, 'hello'));

    expect($prompt)->toContain($this->mine->candidate->candidate_code)
        ->toContain('recruiter')
        ->not->toContain('PRIVATE-USER-DAVE')
        ->not->toContain('PRIVATE-CANDIDATE-ALICE');
});

test('a context pointing at another team\'s candidate adds nothing to the prompt', function (): void {
    $conversation = AiConversation::factory()->create(['user_id' => $this->user->id, 'context_type' => 'candidate', 'context_id' => $this->theirs->candidate_id]);

    $prompt = conversationPrivacyText(app(ConversationContextBuilder::class)->build($conversation, 'hello'));

    expect($prompt)->not->toContain($this->theirs->candidate->candidate_code)
        ->not->toContain('PRIVATE-CANDIDATE-OTHER')
        ->not->toContain('currently viewing');
});

test('the Copilot page drops a tampered candidate context without revealing it, and logs the denial safely', function (): void {
    Log::spy();
    actingAs($this->user);

    get(AiCopilot::getUrl(['context_type' => 'candidate', 'context_id' => $this->theirs->candidate_id]))
        ->assertOk()
        ->assertDontSee('PRIVATE-CANDIDATE-OTHER')
        ->assertDontSee($this->theirs->candidate->candidate_code);

    expect(AiConversation::query()->where('user_id', $this->user->id)->sole())
        ->context_type->toBeNull()
        ->context_id->toBeNull();
    Log::shouldHaveReceived('notice')->withArgs(fn (string $message, array $context) => $context === ['user_id' => $this->user->id, 'context_type' => 'candidate'])->once();
});

test('authorized and record-less contexts are kept', function (): void {
    actingAs($this->user);

    Livewire::withQueryParams(['context_type' => 'candidate', 'context_id' => $this->mine->candidate_id])->test(AiCopilot::class)
        ->assertSet('contextType', 'candidate')->assertSet('contextId', $this->mine->candidate_id);
    Livewire::withQueryParams(['context_type' => 'dashboard'])->test(AiCopilot::class)
        ->assertSet('contextType', 'dashboard');
});

test('the page context cannot be changed through Livewire state', function (): void {
    actingAs($this->user);

    expect(fn () => Livewire::test(AiCopilot::class)->set('contextId', $this->theirs->candidate_id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('AI output shows names only for records the viewer can see', function (): void {
    $conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
    $conversation->messages()->create(['role' => 'assistant', 'content' => "{$this->mine->candidate->candidate_code} and {$this->theirs->candidate->candidate_code} match."]);
    actingAs($this->user);

    Livewire::test(AiCopilot::class)->call('switchConversation', $conversation->id)
        ->assertSee($this->mine->candidate->candidate_code.' — PRIVATE-CANDIDATE-ALICE')
        ->assertSee($this->theirs->candidate->candidate_code)
        ->assertDontSee('PRIVATE-CANDIDATE-OTHER');

    expect($conversation->messages()->value('content'))->not->toContain('PRIVATE-CANDIDATE-ALICE');
});

test('legacy conversations stay intact and read-only: never continued, never replayed', function (): void {
    $provider = new ScriptedLlmProvider([ScriptedLlmProvider::text('should not be used')]);
    app()->instance(LLMProviderInterface::class, $provider);
    $legacy = AiConversation::factory()->create(['user_id' => $this->user->id]);
    $legacy->forceFill(['privacy_version' => null])->save();
    $legacy->messages()->create(['role' => 'assistant', 'content' => 'Legacy answer about PRIVATE-CANDIDATE-ALICE']);
    actingAs($this->user);

    Livewire::test(AiCopilot::class)->call('switchConversation', $legacy->id)
        ->assertSee('kept read-only')
        ->set('question', 'continue please')
        ->call('ask');

    expect(fn () => app(AiOrchestrator::class)->ask($legacy->fresh(), 'continue', $this->user))->toThrow(DomainException::class, 'read-only')
        ->and($provider->calls)->toBe([])
        ->and($legacy->messages()->count())->toBe(1)
        ->and($legacy->messages()->value('content'))->toBe('Legacy answer about PRIVATE-CANDIDATE-ALICE')
        ->and(AiConversation::factory()->create(['user_id' => $this->user->id])->privacy_version)->toBe(AiConversation::PRIVACY_VERSION);
});
