<?php

use App\Enums\CandidateStage;
use App\Filament\Pages\AiCopilot;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolCall;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\AI\Actions\ApprovalParameterPreview;
use App\Services\AI\Tools\ToolRegistry;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Phase 8.10 (P810-AI-01): the approval card shows every decision parameter of a proposed AI
 * action, as stored and as it will run — so "AI recommends, humans decide" is an informed decision.
 */

test('every argument of every tool that needs approval is shown on the approval card', function (): void {
    $tools = collect(app(ToolRegistry::class)->all())->filter(fn ($tool) => $tool->riskLevel()->requiresConfirmation());
    $preview = app(ApprovalParameterPreview::class);

    expect($tools->map->name()->values()->all())->toContain('move_candidates_stage', 'reject_candidates', 'send_candidate_email', 'schedule_interview', 'create_followup', 'assign_candidates_to_recruiter');

    foreach ($tools as $tool) {
        foreach (array_keys($tool->inputSchema()['properties'] ?? []) as $key) {
            $shown = in_array($key, ApprovalParameterPreview::ENTITY_ARGUMENTS, true) || $preview->lines([$key => 'sample-value']) !== [];

            expect($shown)->toBeTrue("{$tool->name()}.{$key} is not shown on the approval card");
        }
    }
});

test('the approval card shows the stage, reason, remarks, schedule, follow-up and full message', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $application = CandidateApplication::factory()->create();
    $reason = RecruitmentRejectionReason::factory()->create(['name' => 'Salary expectations too high']);
    $message = AiMessage::factory()->create(['conversation_id' => AiConversation::factory()->create(['user_id' => $user->id])->id, 'role' => 'assistant', 'content' => 'Proposed actions.']);
    $propose = fn (string $tool, string $risk, array $arguments) => AiToolCall::factory()->create(['message_id' => $message->id, 'tool_name' => $tool, 'risk_level' => $risk, 'requires_confirmation' => true, 'arguments' => $arguments]);

    $propose('move_candidates_stage', 'write', ['application_ids' => [$application->id], 'stage' => CandidateStage::Selected->value, 'remarks' => 'Strong final round']);
    $propose('reject_candidates', 'high_impact', ['application_ids' => [$application->id], 'rejection_reason_id' => $reason->id, 'remarks' => 'Budget']);
    $propose('schedule_interview', 'external', ['application_id' => $application->id, 'interviewer_employee_id' => $user->employee_id, 'scheduled_at' => '2026-11-02 10:30:00', 'mode' => 'video_call', 'meeting_link' => 'https://meet.example.test/abc']);
    $propose('create_followup', 'write', ['application_id' => $application->id, 'followup_type' => 'call', 'followup_date' => '2026-11-03 09:00:00']);
    $propose('send_candidate_email', 'external', ['candidate_id' => $application->candidate_id, 'subject' => 'Next steps', 'body' => "Hi there,\nPlease confirm by Friday."]);
    actingAs($user);

    Livewire::test(AiCopilot::class)
        ->call('switchConversation', $message->conversation_id)
        ->assertSee('Move to stage: Selected')
        ->assertSee('Remarks: Strong final round')
        ->assertSee('Rejection reason: Salary expectations too high')
        ->assertSee('Scheduled for: Mon, 02 Nov 2026 · 10:30 AM')
        ->assertSee('Meeting link: https://meet.example.test/abc')
        ->assertSee('Follow-up due: Tue, 03 Nov 2026 · 09:00 AM')
        ->assertSee('Subject: Next steps')
        ->assertSee('Please confirm by Friday.');
});
