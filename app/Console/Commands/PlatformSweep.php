<?php

namespace App\Console\Commands;

use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformEventSeverity;
use App\Models\TenantDeletionRequest;
use App\Services\Platform\ComplianceExportService;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\TenantDeletionService;
use App\Services\Platform\TenantPurgeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * SaaS-5 (platform task, every 15 minutes): records support grants that ended and requests that
 * lapsed, queues the purges that are due (and resumes stopped or failed ones), deletes expired
 * compliance export artifacts, and raises a critical event for a purge that has used up its
 * attempts. Each step is isolated; correctness never depends on it (an ended grant opens nothing;
 * a purge runs only once due).
 */
#[Signature('platform:sweep')]
#[Description('Expire support access, queue due purges, expire compliance exports (platform)')]
class PlatformSweep extends Command
{
    public function handle(SupportAccessService $support, TenantDeletionService $deletions, ComplianceExportService $exports, PlatformEvents $events): int
    {
        $steps = [
            'support access' => function () use ($support): string {
                $result = $support->expireDue();

                return "{$result['expired']} ended, {$result['lapsed']} requests lapsed";
            },
            'purges queued' => fn (): string => (string) $deletions->dispatchDue(),
            'exports expired' => fn (): string => (string) $exports->expireDue(),
            'purges exhausted' => fn (): string => (string) TenantDeletionRequest::query()->where('status', DeletionRequestStatus::Failed->value)->where('failures', '>=', TenantPurgeService::MAX_FAILURES)->get()
                ->each(fn (TenantDeletionRequest $request) => $events->record('purge.exhausted', PlatformEventSeverity::Critical, "Purge of tenant #{$request->tenant_id} failed {$request->failures} times — needs an operator (Purge now retries it)", $request->tenant, ['request_id' => $request->id, 'error' => $request->last_error], "purge.exhausted:{$request->id}:{$request->attempts}"))
                ->count(),
        ];
        $failed = 0;

        foreach ($steps as $name => $step) {
            try {
                $this->line("{$name}: {$step()}");
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
