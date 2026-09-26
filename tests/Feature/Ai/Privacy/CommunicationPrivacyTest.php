<?php

use App\Enums\CommunicationChannel;
use App\Filament\Pages\AiCopilot;
use App\Models\AiConversation;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Orchestrator\AiOrchestrator;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->application = CandidateApplication::factory()->create();
    $this->candidate = $this->application->candidate;
    $this->candidate->update(['full_name' => 'PRIVATE-CANDIDATE-ALICE', 'email' => 'PRIVATE-ALICE@example.invalid', 'mobile' => '9999912345']);
    $this->conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
});

test('the AI drafts and proposes an email by candidate code; the application resolves the recipient and name at send time', function (): void {
    $provider = new ScriptedLlmProvider([
        ScriptedLlmProvider::toolCall('draft_candidate_email', ['candidate_id' => $this->candidate->id, 'purpose' => 'interview invitation']),
        ScriptedLlmProvider::text("Subject: Interview invitation\n\nHi {{candidate.first_name}}, we would like to invite you to interview."),
        ScriptedLlmProvider::toolCall('send_candidate_email', ['candidate_id' => $this->candidate->id, 'subject' => 'Interview invitation', 'body' => 'Hi {{candidate.first_name}}, we would like to invite you to interview.']),
        ScriptedLlmProvider::text('The email is queued.'),
    ]);
    app()->instance(LLMProviderInterface::class, $provider);

    $turn = app(AiOrchestrator::class)->ask($this->conversation, 'Invite this candidate to interview', $this->user);

    expect($turn['pending'])->toHaveCount(1);

    actingAs($this->user);
    Livewire::test(AiCopilot::class)
        ->call('switchConversation', $this->conversation->id)
        ->assertSee("Candidate: {$this->candidate->candidate_code} — PRIVATE-CANDIDATE-ALICE")
        ->assertSee('Recipient: PRIVATE-ALICE@example.invalid')
        ->call('approveToolCall', $turn['pending'][0]->id);

    $communication = CandidateCommunication::query()->where('candidate_id', $this->candidate->id)->sole();
    $payload = (string) json_encode(collect($provider->calls)->flatMap(fn (array $call) => array_map(fn (LlmMessage $m) => [$m->content, $m->toolCalls], $call['messages']))->all());

    expect($communication->channel)->toBe(CommunicationChannel::Email)
        ->and($communication->recipient)->toBe('PRIVATE-ALICE@example.invalid')
        ->and($communication->body)->toContain('Hi PRIVATE-CANDIDATE-ALICE,')
        ->and($payload)->toContain($this->candidate->candidate_code)
        ->not->toContain('PRIVATE-ALICE@example.invalid')
        ->not->toContain('PRIVATE-CANDIDATE-ALICE')
        ->not->toContain('9999912345')
        ->and(count($provider->calls))->toBe(4);
});

test('the approval preview only resolves people the approver can see', function (): void {
    $recruiter = Employee::factory()->create();
    $manager = User::factory()->create(['employee_id' => $recruiter->id])->assignRole('manager');
    $conversation = AiConversation::factory()->create(['user_id' => $manager->id]);
    $provider = new ScriptedLlmProvider([
        ScriptedLlmProvider::toolCall('send_candidate_email', ['candidate_id' => $this->candidate->id, 'subject' => 'Hello', 'body' => 'Hi']),
    ]);
    app()->instance(LLMProviderInterface::class, $provider);

    app(AiOrchestrator::class)->ask($conversation, 'Email them', $manager);
    actingAs($manager);

    Livewire::test(AiCopilot::class)
        ->call('switchConversation', $conversation->id)
        ->assertDontSee('PRIVATE-CANDIDATE-ALICE')
        ->assertDontSee('PRIVATE-ALICE@example.invalid');
});
