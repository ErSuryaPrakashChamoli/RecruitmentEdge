<?php

namespace Database\Factories;

use App\Enums\OfferLetterTemplateFormat;
use App\Models\OfferLetterTemplate;
use App\Models\OfferLetterTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferLetterTemplateVersion>
 */
class OfferLetterTemplateVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = '<p>Dear candidate, '.fake()->sentence().'</p>';

        return [
            'offer_letter_template_id' => OfferLetterTemplate::factory(),
            'version' => 1,
            'format' => OfferLetterTemplateFormat::RichText,
            'body' => $body,
            'file_path' => null,
            'content_hash' => hash('sha256', $body),
        ];
    }
}
