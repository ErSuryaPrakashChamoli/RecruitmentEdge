<?php

namespace App\Jobs;

use App\Enums\ComplianceExportStatus;
use App\Models\ComplianceExport;
use App\Services\Platform\ComplianceExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-5: builds one compliance export (ComplianceExportService). A platform job — no tenant
 * context; it names the export register entry only. A duplicate job finds the export claimed.
 */
class GenerateComplianceExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 290;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('exports');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(ComplianceExportService $exports): void
    {
        $exports->generate($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        ComplianceExport::query()->whereKey($this->exportId)->whereIn('status', [ComplianceExportStatus::Requested->value, ComplianceExportStatus::Running->value])
            ->update(['status' => ComplianceExportStatus::Failed->value, 'error' => mb_substr('Job failed: '.($exception !== null ? $exception::class : 'unknown'), 0, 255), 'updated_at' => now()]);
        Log::error('platform.compliance_export_failed', ['export_id' => $this->exportId, 'exception' => $exception !== null ? $exception::class : null]);
    }
}
