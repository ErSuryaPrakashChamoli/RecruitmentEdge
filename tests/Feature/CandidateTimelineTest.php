<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Filament\Resources\Candidates\Pages\ViewCandidate;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateTimelineEvent;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\User;
use App\Services\CandidateTimelineService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('an application timeline merges stage changes, interviews, offers and recorded events newest first', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);
    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Screened, remarks: 'Good profile');
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled]);
    Offer::factory()->create(['candidate_application_id' => $application->id]);
    app(CandidateTimelineService::class)->record(
        $application->candidate_id, TimelineEventType::Note, 'Called back later', related: ['application' => $application], occurredAt: now()->addMinute(),
    );

    $timeline = app(CandidateTimelineService::class)->forApplication($application->fresh());

    expect($timeline->first()['title'])->toBe('Called back later')
        ->and($timeline->pluck('type')->all())->toContain('stage_change', 'interview', 'note')
        ->and($timeline->firstWhere('type', 'stage_change')['meta'])->toBe('Good profile');
});

test('a candidate timeline spans every application and candidate-level events, labelled with the application', function (): void {
    $candidate = Candidate::factory()->create();
    $first = CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'current_stage' => CandidateStage::Sourced]);
    $second = CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'current_stage' => CandidateStage::Sourced]);
    app(StageTransitionService::class)->transitionTo($first, CandidateStage::Connected);
    app(StageTransitionService::class)->transitionTo($second, CandidateStage::Interested);
    app(CandidateTimelineService::class)->record($candidate, TimelineEventType::TalentPool, 'Added to High Potential');

    $timeline = app(CandidateTimelineService::class)->forCandidate($candidate->fresh());

    expect($timeline)->toHaveCount(3)
        ->and($timeline->pluck('subtitle')->filter(fn ($s) => str_contains($s, $first->application_code)))->toHaveCount(1)
        ->and($timeline->pluck('title')->all())->toContain('Added to High Potential');
});

test('the portal timeline shows only candidate-visible events and candidate-facing stage labels', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);
    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Shortlisted, remarks: 'Internal: strong but pricey');
    $service = app(CandidateTimelineService::class);
    $service->record($application->candidate_id, TimelineEventType::Note, 'Internal note', 'Recruiter only');
    $service->record($application->candidate_id, TimelineEventType::DocumentUploaded, 'You uploaded your resume', visibility: TimelineVisibility::Candidate);

    $portal = $service->forPortal($application->candidate);

    expect($portal->pluck('title')->all())->toEqualCanonicalizing(['You uploaded your resume', 'Application update: Shortlisted'])
        ->and($portal->pluck('description')->filter()->all())->toBe([]);
});

test('timeline events are append-only', function (): void {
    $event = app(CandidateTimelineService::class)->record(Candidate::factory()->create(), TimelineEventType::Note, 'Original');

    $event->update(['title' => 'Edited']);
})->throws(DomainException::class, 'append-only');

test('recording an event captures its actor, source, related records and metadata', function (): void {
    $application = CandidateApplication::factory()->create();
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id]);
    $actor = Employee::factory()->create();

    $event = app(CandidateTimelineService::class)->record(
        $application->candidate_id, TimelineEventType::InterviewConfirmed, 'Interview confirmed',
        source: TimelineSource::CandidatePortal, visibility: TimelineVisibility::Candidate, actor: $actor,
        related: ['interview' => $interview], metadata: ['channel' => 'portal'],
    );

    expect($event->candidate_application_id)->toBe($application->id)
        ->and($event->requisition_id)->toBe($application->requisition_id)
        ->and($event->interview_id)->toBe($interview->id)
        ->and($event->actor->is($actor))->toBeTrue()
        ->and($event->metadata)->toBe(['channel' => 'portal']);
});

test('a possible duplicate is recorded on the new candidate timeline', function (): void {
    Candidate::factory()->create(['mobile' => '9876543210']);
    $duplicate = Candidate::factory()->create(['mobile' => '9876543210']);

    expect(CandidateTimelineEvent::query()->where('candidate_id', $duplicate->id)->where('event_type', TimelineEventType::DuplicateDetected)->exists())->toBeTrue();
});

test('a recruiter can add an internal note from the Candidate 360 view', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);
    $candidate = Candidate::factory()->create();

    Livewire::test(ViewCandidate::class, ['record' => $candidate->id])
        ->callAction('addNote', ['title' => 'Salary talk', 'description' => 'Expects 12 LPA'])
        ->assertNotified('Note added to the timeline')
        ->assertSee('Salary talk');

    expect($candidate->timelineEvents()->sole()->visibility)->toBe(TimelineVisibility::Internal);
});
