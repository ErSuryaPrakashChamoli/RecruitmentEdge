<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferLetter;
use App\Models\OfferLetterConversion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OfferLetterConversion>
 */
class OfferLetterConversionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'revision' => 1,
            'document_path' => OfferLetter::DIRECTORY.'/test/1-'.Str::uuid().'.docx',
            'status' => OfferLetterConversion::PENDING,
            'requested_at' => now(),
        ];
    }
}
