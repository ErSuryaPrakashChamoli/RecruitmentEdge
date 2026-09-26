<?php

use App\Enums\SignalBand;
use App\Enums\VerificationStatus;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\Location;
use App\Models\TalentSignalSnapshot;
use App\Models\User;
use App\Services\Intelligence\RoleDnaService;
use App\Services\Intelligence\TalentSignalService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->signals = app(TalentSignalService::class);
});

/**
 * @param  array<string, mixed>  $candidate
 */
function signalApplication(array $candidate, array $requisition = []): CandidateApplication
{
    $application = CandidateApplication::factory()->create();
    $application->requisition->update(['skills' => ['PHP', 'Laravel', 'SQL', 'Docker', 'AWS'], 'experience_min' => 3, 'experience_max' => 6, 'qualification' => null, 'salary_min' => null, 'salary_max' => null, 'target_joining_date' => null, ...$requisition]);
    $application->candidate->update(['skills' => null, 'total_experience' => null, 'qualification' => null, 'current_city' => null, 'expected_salary' => null, ...$candidate]);

    return $application->fresh();
}

test('bands follow the published rules', function (array $candidate, SignalBand $band): void {
    expect(app(TalentSignalService::class)->refresh(signalApplication($candidate))->band)->toBe($band);
})->with([
    'strong: all required skills, experience in range' => [['skills' => ['php', 'laravel', 'sql', 'docker', 'aws'], 'total_experience' => 4], SignalBand::Strong],
    'moderate: 3 of 5 skills' => [['skills' => ['PHP', 'Laravel', 'SQL'], 'total_experience' => 4], SignalBand::Moderate],
    'weak: 1 of 5 skills' => [['skills' => ['PHP'], 'total_experience' => 4], SignalBand::Weak],
    'weak: far below experience' => [['skills' => ['PHP', 'Laravel', 'SQL', 'Docker', 'AWS'], 'total_experience' => 0.5], SignalBand::Weak],
    'insufficient: nothing recorded' => [[], SignalBand::InsufficientEvidence],
]);

test('components explain matched and missing skills and the experience fit', function (): void {
    $snapshot = $this->signals->refresh(signalApplication(['skills' => ['php', 'Laravel ', 'Go'], 'total_experience' => 7]));
    $items = $snapshot->components['items'];

    expect($items['skills']['matched'])->toBe(['PHP', 'Laravel'])
        ->and($items['skills']['missing'])->toBe(['SQL', 'Docker', 'AWS'])
        ->and($snapshot->required_coverage_pct)->toBe(40.0)
        ->and($snapshot->experience_fit)->toBe('above')
        ->and($snapshot->evidence()->where('subject_key', 'skills')->count())->toBe(5)
        ->and($snapshot->evidence()->pluck('verification_status')->unique()->all())->toBe([VerificationStatus::NotRequired]);
});

test('location and education only match exactly; anything else is unknown or context, never a penalty', function (): void {
    $location = Location::factory()->create(['name' => 'Pune']);
    $base = ['skills' => ['PHP', 'Laravel', 'SQL', 'Docker', 'AWS'], 'total_experience' => 4];

    $elsewhere = $this->signals->refresh(signalApplication([...$base, 'current_city' => 'Mumbai', 'qualification' => 'BE Computers'], ['location_id' => $location->id, 'qualification' => 'B.Tech']));

    expect($elsewhere->components['items']['location']['status'])->toBe('context')
        ->and($elsewhere->components['items']['education']['status'])->toBe('unknown')
        ->and($elsewhere->band)->toBe(SignalBand::Strong);
});

test('the culture-fit rating is never used', function (): void {
    $application = signalApplication(['skills' => ['PHP'], 'total_experience' => 4]);
    $other = CandidateApplication::factory()->create(['candidate_id' => $application->candidate_id]);
    $interview = Interview::factory()->create(['candidate_application_id' => $other->id]);
    InterviewFeedback::factory()->create(['interview_id' => $interview->id, 'ratings' => ['technical' => 4, 'culture_fit' => 1]]);

    $snapshot = $this->signals->refresh($application);

    expect($snapshot->components['items']['interviews']['summary'])->toContain('Technical 4/5')->not->toContain('Culture Fit 1')
        ->and($snapshot->evidence()->where('subject_key', 'interviews')->first()->value)->not->toContain('culture_fit');
});

test('unconfirmed AI suggestions do not change the signal until a person confirms them', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');
    $application = signalApplication(['skills' => ['PHP', 'Laravel', 'SQL', 'Docker', 'AWS'], 'total_experience' => 4]);
    $roleDna = app(RoleDnaService::class);
    $profile = $roleDna->profileFor($application->requisition);
    $roleDna->currentVersionFor($application->requisition);
    $roleDna->applyAiSuggestions($profile->fresh(), [['category' => 'skill', 'label' => 'Kubernetes', 'level' => 'required', 'reason' => null]], 'm');

    expect($this->signals->refresh($application)->required_skills)->toBe(5);

    $roleDna->confirmAttribute($application->requisition, 'skill:kubernetes', $user);

    expect($this->signals->refresh($application->fresh())->required_skills)->toBe(6);
});

test('a fresh signal is reused; a change supersedes it and keeps the old one', function (): void {
    $application = signalApplication(['skills' => ['PHP'], 'total_experience' => 4]);
    $first = $this->signals->refresh($application);

    expect($this->signals->refresh($application->fresh())->id)->toBe($first->id);

    $this->travel(1)->minute();
    $application->candidate->update(['skills' => ['PHP', 'Laravel', 'SQL', 'Docker', 'AWS']]);
    $second = $this->signals->refresh($application->fresh());

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->is_current)->toBeFalse()
        ->and($first->fresh()->band)->toBe(SignalBand::Weak)
        ->and($second->band)->toBe(SignalBand::Strong)
        ->and(TalentSignalSnapshot::query()->where('is_current', true)->count())->toBe(1);
});
