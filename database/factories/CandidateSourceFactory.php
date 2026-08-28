<?php

namespace Database\Factories;

use App\Models\CandidateSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateSource>
 */
class CandidateSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'Naukri', 'Indeed', 'LinkedIn', 'Apna', 'WorkIndia', 'Website', 'Employee Referral',
                'Walk-in', 'WhatsApp', 'Facebook', 'Instagram', 'Agency', 'Internal Database', 'Other',
            ]),
            'code' => strtoupper(fake()->unique()->lexify('SRC-???')),
            'is_active' => true,
        ];
    }
}
