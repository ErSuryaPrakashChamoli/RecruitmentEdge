<?php

use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\Pages\ListInterviews;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->assignRole('chro');
    actingAs($this->actor);

    $this->interview = Interview::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create()->id,
        'round_number' => 1,
        'status' => InterviewStatus::Scheduled,
    ]);
});

test('completing an interview without feedback shows a notification instead of throwing', function (): void {
    Livewire::test(ListInterviews::class)
        ->callAction(
            TestAction::make('complete')->table($this->interview),
            data: ['result' => InterviewResult::Selected->value],
        )
        ->assertNotified('Interview could not be completed');

    expect($this->interview->fresh()->status)->toBe(InterviewStatus::Scheduled);
});

test('completing an interview that has feedback succeeds from the table', function (): void {
    InterviewFeedback::factory()->create(['interview_id' => $this->interview->id]);

    Livewire::test(ListInterviews::class)
        ->callAction(
            TestAction::make('complete')->table($this->interview),
            data: ['result' => InterviewResult::Selected->value],
        );

    expect($this->interview->fresh()->status)->toBe(InterviewStatus::Completed)
        ->and($this->interview->fresh()->result)->toBe(InterviewResult::Selected);
});

test('feedback can be added from the interviews table so completion is not a dead end', function (): void {
    $interviewer = Employee::factory()->create();

    Livewire::test(ListInterviews::class)
        ->callAction(TestAction::make('addFeedback')->table($this->interview), data: [
            'interviewer_id' => $interviewer->id,
            'recommendation' => 'recommend',
            'feedback' => 'Strong candidate.',
        ]);

    expect(InterviewFeedback::query()->where('interview_id', $this->interview->id)->count())->toBe(1);
});
