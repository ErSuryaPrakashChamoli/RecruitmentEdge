<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\OfferLetter;
use App\Models\OfferRevision;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 8.6 (D8.6-010): issues the offer letter once — when the offer is released (revision 1) and
 * when a revision is released — and serves that stored letter from then on. The PDF, its SHA-256,
 * the source (custom wording, template, built-in) and the exact template version are recorded, so
 * a later template edit, default change or data change never alters a letter the candidate
 * received.
 *
 * Offers released before Phase 8.6 have no stored letter: their download is regenerated from the
 * current data and marked as such (never back-filled).
 */
class OfferLetterIssuanceService
{
    public function __construct(private readonly OfferLetterRenderer $renderer) {}

    /**
     * Render and store the letter for the offer's current terms. Called inside the release
     * transaction; the stored file is removed again if the transaction fails afterwards.
     */
    public function issue(Offer $offer, ?OfferRevision $revision = null, ?int $issuedBy = null): OfferLetter
    {
        $offer->loadMissing([
            'candidateApplication.candidate',
            'candidateApplication.recruiter',
            'candidateApplication.requisition.department',
            'candidateApplication.requisition.designation',
            'candidateApplication.requisition.location',
            'designation',
            'location',
            'offerLetterTemplate',
        ]);

        $source = $this->renderer->sourceFor($offer);
        $template = in_array($source, ['word', 'rich_text'], true) ? $this->renderer->templateFor($offer) : null;
        $version = $template?->ensureCurrentVersion($issuedBy);
        $pdf = $this->renderer->pdf($offer);
        $path = OfferLetter::DIRECTORY."/{$offer->id}/".($revision?->revision ?? 1).'-'.Str::uuid().'.pdf';

        Storage::disk('local')->put($path, $pdf);

        try {
            $letter = OfferLetter::query()->create([
                'offer_id' => $offer->id,
                'offer_revision_id' => $revision?->id,
                'revision' => $revision?->revision ?? 1,
                'source' => $source,
                'offer_letter_template_id' => $template?->id,
                'offer_letter_template_version_id' => $version?->id,
                'file_path' => $path,
                'sha256' => hash('sha256', $pdf),
                'size' => strlen($pdf),
                'issued_by' => $issuedBy,
                'issued_at' => now(),
            ]);

            AuditLog::record($offer, 'offer_letter_issued', null, [
                'offer_letter_id' => $letter->id,
                'revision' => $letter->revision,
                'source' => $source,
                'template_id' => $template?->id,
                'template_version' => $version?->version,
                'sha256' => $letter->sha256,
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        return $letter;
    }

    /**
     * The letter issued most recently for the offer, or null for offers released before 8.6 (or
     * never released).
     */
    public function latestFor(Offer $offer): ?OfferLetter
    {
        return OfferLetter::query()->where('offer_id', $offer->id)->orderByDesc('issued_at')->orderByDesc('id')->first();
    }

    /**
     * The PDF to hand out: the stored issued letter when there is one (verified against its hash),
     * otherwise a letter rendered now from current data.
     *
     * @return array{pdf: string, issued: OfferLetter|null}
     */
    public function pdfFor(Offer $offer): array
    {
        $letter = $this->latestFor($offer);
        $stored = $letter?->contents();

        if ($stored !== null) {
            return ['pdf' => $stored, 'issued' => $letter];
        }

        if ($letter !== null) {
            report(new \RuntimeException("Issued offer letter {$letter->id} is missing or fails its integrity check."));
        }

        return ['pdf' => $this->renderer->pdf($offer), 'issued' => null];
    }
}
