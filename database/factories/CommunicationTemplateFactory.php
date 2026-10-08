<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Models\CommunicationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Prefer CommunicationTemplateService::create() in tests of template behaviour (validation,
 * versioning); this factory is for fixtures.
 *
 * @extends Factory<CommunicationTemplate>
 */
class CommunicationTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'channel' => CommunicationChannel::Email,
            'language' => 'en',
            'subject' => 'Hello {{candidate.first_name}}',
            'body' => 'Hi {{candidate.name}}',
            'status' => TemplateStatus::Active,
        ];
    }
}
