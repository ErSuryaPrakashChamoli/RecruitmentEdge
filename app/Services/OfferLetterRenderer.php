<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\OfferLetterTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use Throwable;

/**
 * Turns an offer into the PDF offer letter sent to the candidate. The letter comes from, in order:
 * the offer's own customised rich-text body, the template chosen for the offer (while active), then
 * the default template — which falls back to the protected Word system template. A Word template's
 * ${merge_tag} placeholders are filled and the document converted to PDF (WordToPdfConverter); rich
 * text is rendered with its merge tags filled. Values are taken from the offer at render time, so
 * later salary or date changes still flow in. With no usable template, the built-in
 * pdf.offer-letter view is used.
 */
class OfferLetterRenderer
{
    /**
     * Merge tags available to templates, keyed by tag id — `${id}` in Word files.
     *
     * @var array<string, string>
     */
    public const MERGE_TAGS = [
        'candidate_name' => 'Candidate name',
        'candidate_email' => 'Candidate email',
        'candidate_mobile' => 'Candidate mobile',
        'designation' => 'Designation',
        'department' => 'Department',
        'location' => 'Location',
        'offer_code' => 'Offer code',
        'offer_date' => 'Offer date',
        'offer_valid_until' => 'Offer valid until',
        'expected_joining_date' => 'Expected joining date',
        'offered_ctc' => 'Offered CTC',
        'fixed_salary' => 'Fixed salary',
        'variable_salary' => 'Variable pay',
        'joining_bonus' => 'Joining bonus',
        'recruiter_name' => 'Recruiter name',
        'company_name' => 'Company name',
        'today' => 'Today\'s date',
    ];

    public function __construct(private readonly WordToPdfConverter $wordToPdf) {}

    public function templateFor(Offer $offer): ?OfferLetterTemplate
    {
        return $offer->offerLetterTemplate?->is_active
            ? $offer->offerLetterTemplate
            : OfferLetterTemplate::defaultTemplate();
    }

    /**
     * Where the letter comes from: 'custom' (the offer's own wording), 'word' or 'rich_text' (a
     * template), or 'built_in'.
     */
    public function sourceFor(Offer $offer): string
    {
        if (filled($offer->offer_letter_body)) {
            return 'custom';
        }

        $template = $this->templateFor($offer);

        if ($template?->isWord()) {
            return $template->hasFile() ? 'word' : 'built_in';
        }

        return filled($template?->body) ? 'rich_text' : 'built_in';
    }

    /**
     * The rich-text body of the letter, or null when the letter comes from a Word file or the
     * built-in view.
     */
    public function bodyFor(Offer $offer): ?string
    {
        return match ($this->sourceFor($offer)) {
            'custom' => $offer->offer_letter_body,
            'rich_text' => $this->templateFor($offer)?->body,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function mergeTagValues(Offer $offer): array
    {
        $offer->loadMissing([
            'candidateApplication.candidate',
            'candidateApplication.recruiter',
            'candidateApplication.requisition.department',
            'candidateApplication.requisition.designation',
            'candidateApplication.requisition.location',
            'designation',
            'location',
        ]);

        $application = $offer->candidateApplication;
        $requisition = $application?->requisition;
        $date = fn (mixed $value): string => $value?->format('d M Y') ?? '—';
        $money = fn (mixed $amount): string => $amount !== null ? '₹'.number_format((float) $amount, 2) : '—';

        return [
            'candidate_name' => $application?->candidate?->full_name ?? '—',
            'candidate_email' => $application?->candidate?->email ?? '—',
            'candidate_mobile' => $application?->candidate?->mobile ?? '—',
            'designation' => ($offer->designation ?? $requisition?->designation)?->name ?? '—',
            'department' => $requisition?->department?->name ?? '—',
            'location' => ($offer->location ?? $requisition?->location)?->name ?? '—',
            'offer_code' => $offer->offer_code ?? '—',
            'offer_date' => $date($offer->offer_date),
            'offer_valid_until' => $date($offer->offer_expiry),
            'expected_joining_date' => $date($offer->expected_joining_date),
            'offered_ctc' => $money($offer->offered_ctc),
            'fixed_salary' => $money($offer->fixed_salary),
            'variable_salary' => $money($offer->variable_salary),
            'joining_bonus' => $money($offer->joining_bonus),
            'recruiter_name' => $application?->recruiter?->fullName() ?? '—',
            'company_name' => (string) config('app.name'),
            'today' => now()->format('d M Y'),
        ];
    }

    /**
     * The sanitized rich-text letter with merge tags filled in, or null when the letter is not rich text.
     */
    public function renderBody(Offer $offer): ?string
    {
        $body = $this->bodyFor($offer);

        if (blank($body)) {
            return null;
        }

        return RichContentRenderer::make($body)
            ->mergeTags($this->mergeTagValues($offer))
            ->toHtml();
    }

    /**
     * The candidate-facing offer letter as PDF binary.
     */
    public function pdf(Offer $offer): string
    {
        return match ($this->sourceFor($offer)) {
            'word' => $this->wordPdf($this->templateFor($offer), $offer),
            'custom', 'rich_text' => Pdf::loadView('pdf.offer-letter-template', [
                'offer' => $offer,
                'bodyHtml' => $this->renderBody($offer),
            ])->output(),
            default => Pdf::loadView('pdf.offer-letter', ['offer' => $offer])->output(),
        };
    }

    /**
     * Fills a Word template's ${placeholders} from the offer and returns the path of a temporary
     * .docx, which the caller must delete.
     */
    public function fillWordTemplate(OfferLetterTemplate $template, Offer $offer): string
    {
        Settings::setOutputEscapingEnabled(true);

        $processor = new TemplateProcessor($template->absoluteFilePath());

        foreach ($this->mergeTagValues($offer) as $tag => $value) {
            $processor->setValue($tag, $value);
        }

        $path = sys_get_temp_dir().'/offer-letter-'.Str::uuid().'.docx';
        $processor->saveAs($path);

        return $path;
    }

    /**
     * ${placeholders} in a Word file that are not known merge tags.
     *
     * @return array<int, string>
     *
     * @throws DomainException when the file is not a readable Word document
     */
    public function unknownPlaceholdersIn(string $absoluteDocxPath): array
    {
        try {
            $variables = (new TemplateProcessor($absoluteDocxPath))->getVariables();
        } catch (Throwable) {
            throw new DomainException('The uploaded file is not a readable Word (.docx) document.');
        }

        return array_values(array_diff(array_unique($variables), array_keys(self::MERGE_TAGS)));
    }

    private function wordPdf(OfferLetterTemplate $template, Offer $offer): string
    {
        $docxPath = $this->fillWordTemplate($template, $offer);

        try {
            return $this->wordToPdf->convert($docxPath);
        } finally {
            File::delete($docxPath);
        }
    }
}
