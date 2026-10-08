<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\CandidatePortalAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidatePortalAccount>
 */
class CandidatePortalAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_id' => Candidate::factory(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CandidatePortalAccount $account): void {
            $account->password ??= 'Secret#12345';
            $account->password_set_at ??= now();
            $account->is_active ??= true;
        });
    }

    public function withoutPassword(): static
    {
        return $this->afterMaking(function (CandidatePortalAccount $account): void {
            $account->password = null;
            $account->password_set_at = null;
        });
    }
}
