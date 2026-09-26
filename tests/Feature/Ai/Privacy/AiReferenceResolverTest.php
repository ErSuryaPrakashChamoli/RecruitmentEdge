<?php

use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\User;
use App\Services\AI\Privacy\AiReferenceResolver;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->recruiter = Employee::factory()->create(['first_name' => 'Rita', 'last_name' => 'Recruiter']);
    $this->user = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
    $this->mine = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    $this->mine->candidate->update(['full_name' => 'Rahul Sharma']);
    $this->theirs = CandidateApplication::factory()->create();
    $this->theirs->candidate->update(['full_name' => 'Hidden Person']);
    $this->resolver = app(AiReferenceResolver::class);
});

test('references the viewer may see are shown with the name; others stay bare codes', function (): void {
    $mine = $this->mine->candidate->candidate_code;
    $theirs = $this->theirs->candidate->candidate_code;

    $resolved = $this->resolver->resolve("Candidate {$mine} fits; {$theirs} does not.", $this->user);

    expect($resolved)->toBe("Candidate {$mine} — Rahul Sharma fits; {$theirs} does not.")
        ->not->toContain('Hidden Person');
});

test('applications, interviews and employees resolve through the same hierarchy rules', function (): void {
    $interview = Interview::factory()->create(['candidate_application_id' => $this->mine->id]);
    $hiddenInterview = Interview::factory()->create(['candidate_application_id' => $this->theirs->id]);
    $outsider = Employee::factory()->create(['first_name' => 'Outside', 'last_name' => 'Manager']);

    $resolved = $this->resolver->resolve(
        "{$this->mine->application_code} INT-{$interview->id} INT-{$hiddenInterview->id} {$this->recruiter->employee_code} {$outsider->employee_code}",
        $this->user,
    );

    expect($resolved)->toContain("{$this->mine->application_code} — Rahul Sharma")
        ->toContain("INT-{$interview->id} — Rahul Sharma")
        ->toContain("{$this->recruiter->employee_code} — Rita Recruiter")
        ->not->toContain('Hidden Person')
        ->not->toContain('Outside Manager');
});

test('names are markdown-escaped for rendered assistant text, and structures resolve every string', function (): void {
    $this->mine->candidate->update(['full_name' => 'A*B [x]']);
    $code = $this->mine->candidate->candidate_code;

    expect($this->resolver->resolve("See {$code}", $this->user, markdown: true))->toBe("See {$code} — A\\*B \\[x\\]")
        ->and($this->resolver->resolveStructure(['summary' => "Ok {$code}", 'nested' => ['ref' => $code, 'n' => 3]], $this->user))
        ->toBe(['summary' => "Ok {$code} — A*B [x]", 'nested' => ['ref' => "{$code} — A*B [x]", 'n' => 3]]);
});
