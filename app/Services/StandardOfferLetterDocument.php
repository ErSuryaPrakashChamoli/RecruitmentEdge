<?php

namespace App\Services;

use App\Models\OfferLetterTemplate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Builds the built-in "Standard Offer Letter" Word file for the protected system template — used by
 * the seeder and by "Restore original". Placeholders use PhpWord's ${merge_tag} syntax with the keys
 * of OfferLetterRenderer::MERGE_TAGS.
 */
class StandardOfferLetterDocument
{
    public const PATH = OfferLetterTemplate::FILE_DIRECTORY.'/standard-offer-letter.docx';

    /**
     * Writes the document to the local disk and returns its path on that disk.
     */
    public function store(string $path = self::PATH): string
    {
        $temporaryPath = sys_get_temp_dir().'/standard-offer-letter-'.uniqid().'.docx';

        try {
            IOFactory::createWriter($this->build(), 'Word2007')->save($temporaryPath);
            Storage::disk('local')->put($path, (string) file_get_contents($temporaryPath));
        } finally {
            File::delete($temporaryPath);
        }

        return $path;
    }

    public function build(): PhpWord
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection();
        $section->addText('${company_name}', ['bold' => true, 'size' => 18]);
        $section->addText('Offer Letter — Ref. ${offer_code}', ['bold' => true]);
        $section->addText('Date: ${offer_date}');
        $section->addTextBreak();
        $section->addText('Dear ${candidate_name},');
        $section->addText('We are pleased to offer you the position of ${designation} in the ${department} department, based at ${location}. The terms of this offer are set out below.');
        $section->addTextBreak();
        $section->addText('Compensation', ['bold' => true, 'size' => 13]);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'D1D5DB', 'cellMargin' => 80]);

        foreach ([
            'Fixed salary' => '${fixed_salary}',
            'Variable pay' => '${variable_salary}',
            'Joining bonus' => '${joining_bonus}',
            'Total CTC' => '${offered_ctc}',
        ] as $label => $placeholder) {
            $style = $label === 'Total CTC' ? ['bold' => true] : [];

            $table->addRow();
            $table->addCell(4000)->addText($label, $style);
            $table->addCell(5000)->addText($placeholder, $style);
        }

        $section->addTextBreak();
        $section->addText('Your expected date of joining is ${expected_joining_date}. Please confirm your acceptance on or before ${offer_valid_until}, after which this offer lapses.');
        $section->addText('We look forward to welcoming you to the team.');
        $section->addTextBreak();
        $section->addText('Sincerely,');
        $section->addText('Human Resources, ${company_name}');

        return $phpWord;
    }
}
