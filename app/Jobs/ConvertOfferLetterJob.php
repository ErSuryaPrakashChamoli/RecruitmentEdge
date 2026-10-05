<?php

namespace App\Jobs;

use App\Logging\SensitiveDataRedactor;
use App\Models\OfferLetterConversion;
use App\Services\OfferLetterIssuanceService;
use App\Services\Tenancy\TenantUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Phase 8.9 (P89-PERF-024): converts a released Word offer letter to its PDF on the `documents` queue
 * and issues the immutable offer letter — the conversion (LibreOffice, up to 120 s) never runs inside
 * the release request. Ids only; re-reads the conversion and does nothing unless it is still pending.
 */
class ConvertOfferLetterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * A lost job must not hold its uniqueness lock forever.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $conversionId)
    {
        $this->onQueue('documents');
    }

    public function uniqueId(): string
    {
        return (string) $this->conversionId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OfferLetterIssuanceService $letters): void
    {
        $conversion = OfferLetterConversion::query()->find($this->conversionId);

        if ($conversion !== null && $conversion->isPending()) {
            $conversion->increment('attempts');
            $letters->completeConversion($conversion);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // SaaS-7 (S7-02): refused only because the tenant is paused — leave the work as it is, so
        // PausedTenantWork can run it again when the tenant is usable (D-S3-15).
        if ($exception instanceof TenantUnavailable) {
            return;
        }

        app(OfferLetterIssuanceService::class)->failConversion(
            $this->conversionId,
            (string) SensitiveDataRedactor::text(mb_substr((string) $exception?->getMessage(), 0, 250)),
        );
    }
}
