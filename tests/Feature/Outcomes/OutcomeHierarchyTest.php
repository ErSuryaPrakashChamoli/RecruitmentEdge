<?php

use App\Enums\OutcomeType;
use App\Filament\Pages\OutcomeDashboard;
use App\Filament\Resources\HiringOutcomes\HiringOutcomeResource;
use App\Filament\Resources\HiringOutcomes\Pages\ListHiringOutcomes;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\HiringOutcome;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    foreach (['a', 'b'] as $side) {
        $manager = Employee::factory()->create();
        $this->{"user_{$side}"} = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
        $this->{"requisition_{$side}"} = RecruitmentRequisition::factory()->create(['manager_id' => $manager->id]);
        $this->{"outcome_{$side}"} = HiringOutcome::factory()->create([
            'outcome_type' => OutcomeType::Joined,
            'requisition_id' => $this->{"requisition_{$side}"}->id,
            'candidate_application_id' => CandidateApplication::factory()->create(['requisition_id' => $this->{"requisition_{$side}"}->id])->id,
        ]);
    }
});

test('Manager A sees only their own outcome records and aggregates', function (): void {
    actingAs($this->user_a);

    Livewire::test(ListHiringOutcomes::class)
        ->assertCanSeeTableRecords([$this->outcome_a])
        ->assertCanNotSeeTableRecords([$this->outcome_b]);

    $report = Livewire::test(OutcomeDashboard::class)->instance()->report();

    expect($report['metrics']['joining']['counts']['joined'])->toBe(1);
});

test('Manager A cannot open or filter by Manager B\'s outcomes or requisition', function (): void {
    actingAs($this->user_a);

    get(HiringOutcomeResource::getUrl('view', ['record' => $this->outcome_b]))->assertNotFound();

    $options = Livewire::test(OutcomeDashboard::class)->instance()->form->getComponent('requisition_id')->getOptions();
    $filtered = Livewire::test(OutcomeDashboard::class)->set('data.requisition_id', $this->requisition_b->id)->instance()->report();

    expect($options)->toHaveKey($this->requisition_a->id)
        ->not->toHaveKey($this->requisition_b->id)
        ->and($filtered['metrics']['joining']['counts']['joined'])->toBe(0);
});

test('a recruiter has no access to outcomes', function (): void {
    actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter'));

    get(HiringOutcomeResource::getUrl())->assertForbidden();
    get(HiringOutcomeResource::getUrl('view', ['record' => $this->outcome_a]))->assertNotFound();
});
