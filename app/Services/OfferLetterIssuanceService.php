<?php

namespace App\Services;

use App\Filament\Resources\Offers\OfferResource;
use App\Jobs\ConvertOfferLetterJob;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\OfferLetter;
use App\Models\OfferLetterConversion;
use App\Models\OfferLetterTemplate;
use App\Models\OfferLetterTemplateVersion;
use App\Models\OfferRevision;
use App\Services\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
    public function __construct(
        private readonly OfferLetterRenderer $renderer,
        private readonly WordToPdfConverter $converter,
    ) {}

    /**
     * Render and store the letter for the offer's current terms. Called inside the release
     * transaction; the stored file is removed again if the transaction fails afterwards.
     *
     * Phase 8.9 (P89-PERF-024): a Word letter is filled now — its content is fixed at release — and
     * stored as a pending conversion; ConvertOfferLetterJob produces the PDF after the release commits
     * and issues the letter then (returns null here). Rich-text and built-in letters (DomPDF, fast)
     * are issued at once, as before.
     */
    public function issue(Offer $offer, ?OfferRevision $revision = null, ?int $issuedBy = null): ?OfferLetter
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

        if ($source === 'word' && $template !== null) {
            $this->queueConversion($offer, $revision, $template->id, $version?->id, $issuedBy, $template);

            return null;
        }

        $pdf = $this->renderer->pdf($offer);
        $path = TenantStorage::path(OfferLetter::DIRECTORY)."/{$offer->id}/".($revision?->revision ?? 1).'-'.Str::uuid().'.pdf';

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
     * Stores the filled Word letter and records its pending conversion; the job is queued once the
     * release commits.
     */
    private function queueConversion(Offer $offer, ?OfferRevision $revision, int $templateId, ?int $versionId, ?int $issuedBy, OfferLetterTemplate $template): OfferLetterConversion
    {
        $filled = $this->renderer->fillWordTemplate($template, $offer);
        $path = TenantStorage::path(OfferLetter::DIRECTORY)."/{$offer->id}/".($revision?->revision ?? 1).'-'.Str::uuid().'.docx';

        try {
            Storage::disk('local')->put($path, (string) file_get_contents($filled));
        } finally {
            File::delete($filled);
        }

        try {
            $conversion = OfferLetterConversion::query()->create([
                'offer_id' => $offer->id,
                'offer_revision_id' => $revision?->id,
                'revision' => $revision?->revision ?? 1,
                'offer_letter_template_id' => $templateId,
                'offer_letter_template_version_id' => $versionId,
                'document_path' => $path,
                'status' => OfferLetterConversion::PENDING,
                'issued_by' => $issuedBy,
                'requested_at' => now(),
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }

        ConvertOfferLetterJob::dispatch($conversion->id)->afterCommit();

        return $conversion;
    }

    /**
     * Converts a pending Word letter to its PDF and issues the immutable offer letter, with the
     * release time as its issue time. Run by ConvertOfferLetterJob.
     */
    public function completeConversion(OfferLetterConversion $conversion): ?OfferLetter
    {
        $docx = Storage::disk('local')->path($conversion->document_path);
        $pdf = $this->converter->convert($docx);
        $path = preg_replace('/\.docx$/', '.pdf', $conversion->document_path) ?? $conversion->document_path.'.pdf';

        Storage::disk('local')->put($path, $pdf);

        try {
            return DB::transaction(function () use ($conversion, $pdf, $path): ?OfferLetter {
                $locked = OfferLetterConversion::query()->whereKey($conversion->id)->lockForUpdate()->first();

                if ($locked === null || ! $locked->isPending()) {
                    Storage::disk('local')->delete($path);

                    return null;
                }

                $letter = OfferLetter::query()->create([
                    'offer_id' => $locked->offer_id,
                    'offer_revision_id' => $locked->offer_revision_id,
                    'revision' => $locked->revision,
                    'source' => 'word',
                    'offer_letter_template_id' => $locked->offer_letter_template_id,
                    'offer_letter_template_version_id' => $locked->offer_letter_template_version_id,
                    'file_path' => $path,
                    'sha256' => hash('sha256', $pdf),
                    'size' => strlen($pdf),
                    'issued_by' => $locked->issued_by,
                    'issued_at' => $locked->requested_at,
                ]);

                $locked->update(['status' => OfferLetterConversion::ISSUED, 'offer_letter_id' => $letter->id, 'completed_at' => now(), 'error' => null]);

                AuditLog::record($locked->offer, 'offer_letter_issued', null, [
                    'offer_letter_id' => $letter->id,
                    'revision' => $letter->revision,
                    'source' => 'word',
                    'template_id' => $letter->offer_letter_template_id,
                    'template_version' => $locked->offer_letter_template_version_id !== null ? OfferLetterTemplateVersion::query()->whereKey($locked->offer_letter_template_version_id)->value('version') : null,
                    'sha256' => $letter->sha256,
                    'converted_after_release' => true,
                ]);

                return $letter;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * A conversion whose retries are spent: marked failed (redacted reason), audited, and the releaser
     * is alerted — the released offer stands; the letter can be issued again by re-releasing a revision.
     */
    public function failConversion(int $conversionId, string $reason): void
    {
        $conversion = OfferLetterConversion::query()->find($conversionId);

        if ($conversion === null || ! $conversion->isPending()) {
            return;
        }

        $conversion->update(['status' => OfferLetterConversion::FAILED, 'error' => mb_substr($reason, 0, 255), 'completed_at' => now()]);
        AuditLog::record($conversion->offer, 'offer_letter_conversion_failed', null, ['conversion_id' => $conversion->id, 'revision' => $conversion->revision]);

        app(NotificationDispatchService::class)->alert(
            $conversion->issuer?->user,
            'Offers',
            'Offer letter could not be produced',
            "The PDF of offer {$conversion->offer?->offer_code} (revision {$conversion->revision}) could not be produced; the offer itself is released. Ask an administrator to check the Word template and conversion service.",
            'danger',
            OfferResource::getUrl('view', ['record' => $conversion->offer_id]),
            "offer-letter-conversion-failed-{$conversion->id}",
        );
    }

    /**
     * The newest pending Word-letter conversion of the offer that is not yet issued, if any.
     */
    public function pendingConversionFor(Offer $offer): ?OfferLetterConversion
    {
        return OfferLetterConversion::query()->where('offer_id', $offer->id)->where('status', OfferLetterConversion::PENDING)->latest('id')->first();
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
     * The letter to hand out: the stored issued letter when there is one (verified against its hash),
     * otherwise a letter rendered now from current data.
     *
     * Phase 8.9 (P89-PERF-024): a Word letter is never converted inside the request — without a stored
     * letter (a draft preview, or an offer released before letters were kept) the filled Word document
     * itself is returned; callers check pendingConversionFor() first, so a letter still being produced
     * is never answered with an older one.
     *
     * @return array{pdf: string, issued: OfferLetter|null, format: 'pdf'|'docx'}
     */
    public function pdfFor(Offer $offer): array
    {
        $letter = $this->latestFor($offer);
        $stored = $letter?->contents();

        if ($stored !== null) {
            return ['pdf' => $stored, 'issued' => $letter, 'format' => 'pdf'];
        }

        if ($letter !== null) {
            report(new \RuntimeException("Issued offer letter {$letter->id} is missing or fails its integrity check."));
        }

        if ($this->renderer->sourceFor($offer) === 'word' && ($template = $this->renderer->templateFor($offer)) !== null) {
            $filled = $this->renderer->fillWordTemplate($template, $offer);

            try {
                return ['pdf' => (string) file_get_contents($filled), 'issued' => null, 'format' => 'docx'];
            } finally {
                File::delete($filled);
            }
        }

        return ['pdf' => $this->renderer->pdf($offer), 'issued' => null, 'format' => 'pdf'];
    }
}
