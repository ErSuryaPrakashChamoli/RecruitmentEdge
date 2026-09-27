<?php

use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\CandidateApplications\Pages\EditCandidateApplication;
use App\Filament\Resources\CandidateJoinings\Pages\EditCandidateJoining;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Interviews\Pages\EditInterview;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\RecruitmentRequisitions\Pages\EditRecruitmentRequisition;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Security containment (Phase 8.6 discovery): Filament treats a missing policy method as allowed,
 * so records whose policies had no delete / forceDelete / restore could be deleted by anyone who
 * could open them. Hiring facts now change only through their lifecycle services.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->recruiter = Employee::factory()->create();
    actingAs(User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter'));
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
});

test('a recruiter cannot delete an accepted offer, an interview or a joined record', function (): void {
    $offer = lifecycleFixture(fn () => Offer::factory()->create(['candidate_application_id' => $this->application->id, 'status' => OfferStatus::Accepted]));
    $interview = lifecycleFixture(fn () => Interview::factory()->create(['candidate_application_id' => $this->application->id]));
    $joining = lifecycleFixture(fn () => CandidateJoining::factory()->create(['candidate_application_id' => $this->application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()]));

    Livewire::test(EditOffer::class, ['record' => $offer->getRouteKey()])->assertActionHidden(DeleteAction::class);
    Livewire::test(EditInterview::class, ['record' => $interview->getRouteKey()])->assertActionHidden(DeleteAction::class);
    Livewire::test(EditCandidateJoining::class, ['record' => $joining->getRouteKey()])->assertActionHidden(DeleteAction::class);

    expect(Offer::query()->whereKey($offer->id)->exists())->toBeTrue()
        ->and(Interview::query()->whereKey($interview->id)->exists())->toBeTrue()
        ->and(CandidateJoining::query()->whereKey($joining->id)->exists())->toBeTrue();
});

test('a recruiter cannot delete or force-delete a candidate or an application', function (): void {
    $candidate = Candidate::factory()->create(['created_by' => $this->recruiter->id]);

    Livewire::test(EditCandidate::class, ['record' => $candidate->getRouteKey()])->assertActionHidden(DeleteAction::class);
    Livewire::test(EditCandidateApplication::class, ['record' => $this->application->getRouteKey()])->assertActionHidden(DeleteAction::class);

    expect($candidate->fresh()->trashed())->toBeFalse()
        ->and($this->application->fresh()->trashed())->toBeFalse();
});

test('a requisition can be soft-deleted and restored by its manager, but never force-deleted', function (): void {
    $manager = Employee::factory()->create();
    actingAs(User::factory()->create(['employee_id' => $manager->id])->assignRole('manager'));
    $requisition = lifecycleFixture(fn () => RecruitmentRequisition::factory()->create(['manager_id' => $manager->id, 'status' => RequisitionStatus::Draft]));
    $requisition->delete();

    Livewire::test(EditRecruitmentRequisition::class, ['record' => $requisition->getRouteKey()])
        ->assertActionHidden(ForceDeleteAction::class)
        ->assertActionVisible(RestoreAction::class);

    expect(RecruitmentRequisition::withTrashed()->whereKey($requisition->id)->exists())->toBeTrue();
});
