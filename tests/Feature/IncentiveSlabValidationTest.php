<?php

use App\Filament\Resources\RecruitmentIncentiveRules\Pages\EditRecruitmentIncentiveRule;
use App\Filament\Resources\RecruitmentIncentiveRules\RelationManagers\SlabsRelationManager;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->rule = RecruitmentIncentiveRule::factory()->create();
});

function slabFor(RecruitmentIncentiveRule $rule, float $min, ?float $max): RecruitmentIncentiveSlab
{
    return RecruitmentIncentiveSlab::factory()->create([
        'incentive_rule_id' => $rule->id,
        'achievement_min' => $min,
        'achievement_max' => $max,
    ]);
}

test('non-overlapping bands with a single open-ended top band are accepted', function (): void {
    slabFor($this->rule, 0, 49.99);
    slabFor($this->rule, 50, 79.99);
    slabFor($this->rule, 80, null);

    expect($this->rule->slabs()->count())->toBe(3);
});

test('an upper bound that is not above the lower bound is rejected', function (): void {
    slabFor($this->rule, 50, 50);
})->throws(DomainException::class, 'greater than the lower bound');

test('overlapping bands within the same rule are rejected', function (): void {
    slabFor($this->rule, 0, 50);
    slabFor($this->rule, 50, 80);
})->throws(DomainException::class, 'overlaps');

test('the same band on a different rule is not treated as an overlap', function (): void {
    slabFor($this->rule, 0, 50);
    slabFor(RecruitmentIncentiveRule::factory()->create(), 0, 50);

    expect(RecruitmentIncentiveSlab::query()->count())->toBe(2);
});

test('a second open-ended slab is rejected', function (): void {
    slabFor($this->rule, 80, null);
    slabFor($this->rule, 100, null);
})->throws(DomainException::class, 'only one slab may have no upper bound');

test('an open-ended slab below an existing band is rejected', function (): void {
    slabFor($this->rule, 50, 80);
    slabFor($this->rule, 0, null);
})->throws(DomainException::class, 'highest band');

test('a band above the open-ended slab is rejected', function (): void {
    slabFor($this->rule, 50, null);
    slabFor($this->rule, 60, 70);
})->throws(DomainException::class, 'must remain the highest band');

test('editing a slab does not compare it against itself', function (): void {
    $slab = slabFor($this->rule, 0, 49.99);

    $slab->update(['achievement_max' => 40]);

    expect((float) $slab->refresh()->achievement_max)->toBe(40.0);
});

describe('slabs relation manager form', function (): void {
    beforeEach(function (): void {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('chro');
        actingAs($user);
    });

    test('an overlapping band is shown as a form error and not saved', function (): void {
        slabFor($this->rule, 0, 50);

        Livewire::test(SlabsRelationManager::class, ['ownerRecord' => $this->rule, 'pageClass' => EditRecruitmentIncentiveRule::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), data: [
                'achievement_min' => 40,
                'achievement_max' => 70,
                'amount' => 1000,
            ])
            ->assertHasFormErrors(['achievement_max']);

        expect($this->rule->slabs()->count())->toBe(1);
    });

    test('a valid band is created from the form', function (): void {
        slabFor($this->rule, 0, 49.99);

        Livewire::test(SlabsRelationManager::class, ['ownerRecord' => $this->rule, 'pageClass' => EditRecruitmentIncentiveRule::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), data: [
                'achievement_min' => 50,
                'achievement_max' => null,
                'amount' => 1500,
            ])
            ->assertHasNoFormErrors();

        expect($this->rule->slabs()->count())->toBe(2);
    });

    test('editing a slab in the form ignores the slab itself when checking overlaps', function (): void {
        $slab = slabFor($this->rule, 0, 49.99);

        Livewire::test(SlabsRelationManager::class, ['ownerRecord' => $this->rule, 'pageClass' => EditRecruitmentIncentiveRule::class])
            ->callAction(TestAction::make(EditAction::class)->table($slab), data: [
                'achievement_min' => 0,
                'achievement_max' => 45,
                'amount' => 900,
            ])
            ->assertHasNoFormErrors();

        expect((float) $slab->refresh()->achievement_max)->toBe(45.0);
    });
});
