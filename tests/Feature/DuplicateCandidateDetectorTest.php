<?php

use App\Enums\DuplicateMatchType;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\CandidateDuplicateDetector;
use App\Services\CandidateIdentityNormalizer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function detectDuplicates(array $attributes): Collection
{
    return app(CandidateDuplicateDetector::class)->detect($attributes);
}

test('an exact mobile match is a 100% match', function (): void {
    $existing = Candidate::factory()->create(['mobile' => '9876543210']);

    $match = detectDuplicates(['full_name' => 'Someone Else', 'mobile' => '9876543210'])->sole();

    expect($match->candidate->id)->toBe($existing->id)
        ->and($match->type)->toBe(DuplicateMatchType::Mobile)
        ->and($match->confidence)->toBe(100)
        ->and($match->matchingFields)->toBe(['mobile'])
        ->and($match->isStrong())->toBeTrue();
});

test('an exact email match is a 100% match', function (): void {
    Candidate::factory()->create(['email' => 'rahul@example.com']);

    expect(detectDuplicates(['email' => 'rahul@example.com'])->sole()->type)->toBe(DuplicateMatchType::Email);
});

test('formatting differences in mobile and email are caught as normalised matches', function (array $input, DuplicateMatchType $type): void {
    Candidate::factory()->create(['mobile' => '9876543210', 'email' => 'rahul.sharma@gmail.com']);

    $match = detectDuplicates($input)->sole();

    expect($match->type)->toBe($type)->and($match->isStrong())->toBeTrue();
})->with([
    'country code and separators' => [['mobile' => '+91 98765-43210'], DuplicateMatchType::NormalizedMobile],
    'trunk prefix' => [['mobile' => '09876543210'], DuplicateMatchType::NormalizedMobile],
    'email case and plus tag' => [['email' => 'Rahul.Sharma+jobs@Gmail.com'], DuplicateMatchType::NormalizedEmail],
    'gmail dots' => [['email' => 'rahulsharma@googlemail.com'], DuplicateMatchType::NormalizedEmail],
]);

test('an alternate mobile matching an existing primary mobile is a strong match', function (): void {
    Candidate::factory()->create(['mobile' => '9876543210']);

    expect(detectDuplicates(['mobile' => '9000000000', 'alternate_mobile' => '9876543210'])->sole()->type)->toBe(DuplicateMatchType::AlternateMobile);
});

test('the same name with a partially matching mobile is a possible but not strong match', function (): void {
    Candidate::factory()->create(['full_name' => 'Rahul Sharma', 'mobile' => '9876543210', 'email' => 'a@example.com']);

    $match = detectDuplicates(['full_name' => 'sharma  RAHUL', 'mobile' => '8816543210'])->sole();

    expect($match->type)->toBe(DuplicateMatchType::NameAndContact)
        ->and($match->confidence)->toBe(70)
        ->and($match->isStrong())->toBeFalse();
});

test('the same name alone is not treated as a duplicate', function (): void {
    Candidate::factory()->create(['full_name' => 'Rahul Sharma', 'mobile' => '9876543210', 'email' => 'a@example.com']);

    expect(detectDuplicates(['full_name' => 'Rahul Sharma', 'mobile' => '7000000001', 'email' => 'b@example.org']))->toBeEmpty();
});

test('several existing candidates are each reported once with their strongest signal, strongest first', function (): void {
    $byEmail = Candidate::factory()->create(['email' => 'shared@example.com']);
    $byBoth = Candidate::factory()->create(['mobile' => '9123456780', 'email' => 'SHARED@example.com']);

    $matches = detectDuplicates(['mobile' => '9123456780', 'email' => 'shared@example.com']);

    expect($matches->map(fn ($m) => $m->candidate->id)->all())->toBe([$byBoth->id, $byEmail->id])
        ->and($matches->first()->matchingFields)->toBe(['mobile', 'email']);
});

test('soft-deleted candidates are not reported', function (): void {
    Candidate::factory()->create(['mobile' => '9876543210'])->delete();

    expect(detectDuplicates(['mobile' => '9876543210']))->toBeEmpty();
});

test('contact details are masked in the summary shown to users', function (): void {
    expect(CandidateIdentityNormalizer::maskMobile('+91 98765 43210'))->toBe('XXXXXXX210')
        ->and(CandidateIdentityNormalizer::maskEmail('rahul@example.com'))->toBe('r••••@example.com');
});

test('normalised identity columns are kept in sync on save', function (): void {
    $candidate = Candidate::factory()->create(['mobile' => '+91-98765 43210', 'email' => ' A.B+x@Gmail.com ', 'full_name' => 'Sharma, Rahul']);

    expect($candidate->fresh()->mobile_normalized)->toBe('9876543210')
        ->and($candidate->fresh()->email_normalized)->toBe('ab@gmail.com')
        ->and($candidate->fresh()->name_normalized)->toBe('rahul sharma');
});

describe('creating a candidate from the form', function (): void {
    beforeEach(function (): void {
        $this->seed(RolePermissionSeeder::class);
        $this->source = CandidateSource::factory()->create();
        $this->existing = Candidate::factory()->create(['full_name' => 'Rahul Sharma', 'mobile' => '9876543210']);
    });

    function loginWithRole(string $role): User
    {
        $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $user->assignRole($role);
        actingAs($user);

        return $user;
    }

    test('a strong match halts creation and shows the existing candidate masked', function (): void {
        loginWithRole('recruiter');

        Livewire::test(CreateCandidate::class)
            ->fillForm(['full_name' => 'Rahul S', 'mobile' => '+91 98765 43210', 'source_id' => $this->source->id])
            ->call('create')
            ->assertNotified('Potential duplicate detected')
            ->assertSet('duplicateMatches.0.mobile', 'XXXXXXX210')
            ->assertSet('duplicateMatches.0.candidate_code', $this->existing->candidate_code);

        expect(Candidate::query()->count())->toBe(1);
    });

    test('a user without the override permission cannot create the duplicate even with a justification', function (): void {
        loginWithRole('recruiter');

        Livewire::test(CreateCandidate::class)
            ->fillForm(['full_name' => 'Rahul S', 'mobile' => '9876543210', 'source_id' => $this->source->id, 'duplicate_override_reason' => 'Different person'])
            ->call('create')
            ->assertNotified('Potential duplicate detected');

        expect(Candidate::query()->count())->toBe(1);
    });

    test('an authorised user can create the candidate with a justification, which is audited', function (): void {
        loginWithRole('manager');

        Livewire::test(CreateCandidate::class)
            ->fillForm(['full_name' => 'Rahul Sharma', 'mobile' => '9876543210', 'source_id' => $this->source->id])
            ->call('create')
            ->fillForm(['full_name' => 'Rahul Sharma', 'mobile' => '9876543210', 'source_id' => $this->source->id, 'duplicate_override_reason' => 'Father and son share the number'])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Candidate::query()->latest('id')->first();
        $audit = AuditLog::query()->where('action', 'duplicate_override')->where('auditable_id', $created->id)->sole();

        expect($created->id)->not->toBe($this->existing->id)
            ->and($audit->getAttribute('changes')['justification'])->toBe('Father and son share the number')
            ->and($audit->getAttribute('changes')['matches'][0])->toMatchArray(['candidate_id' => $this->existing->id, 'match_type' => 'mobile']);
    });

    test('a candidate with no strong match is created without interruption', function (): void {
        loginWithRole('recruiter');

        Livewire::test(CreateCandidate::class)
            ->fillForm(['full_name' => 'Priya Nair', 'mobile' => '9000011111', 'source_id' => $this->source->id])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Potential duplicate detected');

        expect(Candidate::query()->where('full_name', 'Priya Nair')->exists())->toBeTrue();
    });
});
