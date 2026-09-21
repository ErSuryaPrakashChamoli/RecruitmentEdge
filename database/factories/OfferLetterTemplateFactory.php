<?php

namespace Database\Factories;

use App\Enums\OfferLetterTemplateFormat;
use App\Models\OfferLetterTemplate;
use App\Services\StandardOfferLetterDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OfferLetterTemplate>
 */
class OfferLetterTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)).' Offer Letter',
            'format' => OfferLetterTemplateFormat::RichText,
            'body' => '<p>Dear <span data-type="mergeTag" data-id="candidate_name"></span>, your offered CTC is <span data-type="mergeTag" data-id="offered_ctc"></span>.</p>',
            'is_default' => false,
            'is_active' => true,
            'is_system' => false,
        ];
    }

    /**
     * The template offers use when they have not picked or customised one.
     */
    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * A Word template holding a copy of the standard offer letter file on the local disk.
     */
    public function word(): static
    {
        return $this->state(fn (): array => [
            'format' => OfferLetterTemplateFormat::Word,
            'body' => null,
            'file_path' => app(StandardOfferLetterDocument::class)->store(OfferLetterTemplate::FILE_DIRECTORY.'/'.Str::uuid().'.docx'),
        ]);
    }
}
