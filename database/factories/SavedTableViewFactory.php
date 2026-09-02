<?php

namespace Database\Factories;

use App\Models\SavedTableView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedTableView>
 */
class SavedTableViewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resource' => 'App\\Filament\\Resources\\CandidateApplications\\Pages\\ListCandidateApplications',
            'name' => fake()->words(3, true),
            'filters' => [],
            'sort' => null,
            'search' => null,
            'is_default' => false,
        ];
    }
}
