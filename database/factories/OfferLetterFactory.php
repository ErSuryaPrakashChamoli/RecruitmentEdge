<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferLetter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OfferLetter>
 */
class OfferLetterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'revision' => 1,
            'source' => 'built_in',
            'file_path' => OfferLetter::DIRECTORY.'/'.Str::uuid().'.pdf',
            'sha256' => hash('sha256', 'pdf'),
            'size' => 3,
            'issued_at' => now(),
        ];
    }
}
