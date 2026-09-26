<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Events\InterviewCancelled;
use App\Filament\Resources\CandidateApplications\Pages\ListCandidateApplications;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\Lifecycle\ApplicationClosureCascade;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->manager->id, 'current_stage' => CandidateStage::OfferReleased]);
    $this->openInterview = Interview::factory()->create(['candidate_application_id' => $this->application->id, 'status' => InterviewStatus::Scheduled]);
    $this->completedInterview = Interview::factory()->create(['candidate_application_id' => $this->application->id, 'status' => InterviewStatus::Completed]);
    $this->offer = Offer::factory()->create(['candidate_application_id' => $this->application->id, 'status' => OfferStatus::Released]);
    $this->joining = CandidateJoining::factory()->create(['candidate_application_id' => $this->application->id, 'offer_id' => $this->offer->id, 'status' => JoiningStatus::Confirmed]);
    $this->reason = RecruitmentRejectionReason::factory()->create(['name' => 'Failed reference check']);
    $this->transitions = app(StageTransitionService::class);
});

test('rejecting an application closes its open interviews, offers and joining — never deleting them', function (): void {
    Event::fake([InterviewCancelled::class]);

    $this->transitions->reject($this->application, $this->reason, $this->manager);
    $audit = AuditLog::query()->where('action', 'lifecycle_cascade')->sole();

    expect($this->openInterview->fresh()->status)->toBe(InterviewStatus::Cancelled)
        ->and($this->openInterview->fresh()->remarks)->toContain('Cancelled due to application rejection')
        ->and($this->completedInterview->fresh()->status)->toBe(InterviewStatus::Completed)
        ->and($this->offer->fresh()->status)->toBe(OfferStatus::Withdrawn)
        ->and($this->offer->statusHistory()->latest('id')->first()->remarks)->toBe('Withdrawn due to application rejection')
        ->and($this->joining->fresh()->status)->toBe(JoiningStatus::Cancelled)
        ->and($this->joining->fresh()->remarks)->toContain('Failed reference check')
        ->and($audit->changes)->toMatchArray(['cause' => 'application_rejected', 'interviews_cancelled' => [$this->openInterview->id], 'offers_withdrawn' => 1, 'joining' => 'cancelled']);
    Event::assertDispatched(InterviewCancelled::class, fn (InterviewCancelled $event) => $event->cause === 'application_rejected');
});

test('a dropout records the joining as a dropout with the same reason', function (): void {
    $this->transitions->dropout($this->application, $this->reason, $this->manager);

    expect($this->joining->fresh()->status)->toBe(JoiningStatus::Dropout)
        ->and($this->joining->fresh()->dropout_reason_id)->toBe($this->reason->id)
        ->and($this->openInterview->fresh()->remarks)->toContain('Cancelled due to application dropout')
        ->and($this->offer->fresh()->status)->toBe(OfferStatus::Withdrawn);
});

test('an accepted offer stays accepted when the application is later rejected', function (): void {
    lifecycleFixture(fn () => $this->offer->forceFill(['status' => OfferStatus::Accepted])->save());

    $this->transitions->reject($this->application, $this->reason, $this->manager);

    expect($this->offer->fresh()->status)->toBe(OfferStatus::Accepted)
        ->and($this->joining->fresh()->status)->toBe(JoiningStatus::Cancelled);
});

test('the cascade is idempotent: closing again changes nothing and records nothing', function (): void {
    $this->transitions->reject($this->application, $this->reason, $this->manager);

    $again = app(ApplicationClosureCascade::class)->close($this->application->fresh(), ApplicationStatus::Rejected, $this->reason, $this->manager);

    expect($again)->toBe(['interviews_cancelled' => [], 'offers_withdrawn' => 0, 'joining' => null])
        ->and(AuditLog::query()->where('action', 'lifecycle_cascade')->count())->toBe(1)
        ->and(fn () => $this->transitions->reject($this->application->fresh(), $this->reason, $this->manager))->toThrow(DomainException::class, 'already Rejected');
});

test('if the cascade fails, the rejection itself is rolled back', function (): void {
    $this->mock(ApplicationClosureCascade::class)->shouldReceive('close')->andThrow(new RuntimeException('cascade failed'));

    expect(fn () => $this->transitions->reject($this->application, $this->reason, $this->manager))->toThrow(RuntimeException::class)
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and($this->application->fresh()->rejection_reason_id)->toBeNull();
});

test('rejecting from the applications table cascades the same way', function (): void {
    actingAs($this->user);

    Livewire::test(ListCandidateApplications::class)
        ->callTableAction('reject', $this->application, ['rejection_reason_id' => $this->reason->id]);

    expect($this->application->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($this->openInterview->fresh()->status)->toBe(InterviewStatus::Cancelled)
        ->and($this->joining->fresh()->status)->toBe(JoiningStatus::Cancelled);
});
