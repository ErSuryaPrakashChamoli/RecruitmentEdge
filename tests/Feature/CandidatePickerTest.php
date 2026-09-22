<?php

use App\Filament\Resources\CandidateApplications\Pages\CreateCandidateApplication;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

test('the application form candidate picker shows the mobile number and searches by it', function (): void {
    $this->seed(RolePermissionSeeder::class);

    $candidate = Candidate::factory()->create(['full_name' => 'Asha Menon', 'mobile' => '9123456780']);
    Candidate::factory()->create(['full_name' => 'Asha Menon', 'mobile' => '9988776655']);

    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('chro');
    actingAs($user);

    $picker = Livewire::test(CreateCandidateApplication::class)->instance()->form->getFlatFields()['candidate_id'];

    expect($picker->getOptions())->toContain('Asha Menon · 9123456780', 'Asha Menon · 9988776655')
        ->and($picker->getSearchResults('9123456780'))->toBe([$candidate->id => 'Asha Menon · 9123456780']);
});
