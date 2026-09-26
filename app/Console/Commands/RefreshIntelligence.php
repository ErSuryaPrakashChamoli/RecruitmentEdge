<?php

namespace App\Console\Commands;

use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Services\Intelligence\HiringHealthService;
use App\Services\Intelligence\HiringRiskRadar;
use App\Services\Intelligence\IntelligenceAiService;
use App\Services\Intelligence\TalentSignalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * EDGE Intelligence refresh (Phase 7), hourly: Hiring Health snapshots for open requisitions whose
 * snapshot is stale, stale Talent Signals for their active applications, then the Risk Radar scan
 * (which also auto-resolves risks no longer observed). Deterministic, bounded, idempotent within
 * each snapshot's freshness window — no AI is called here. AI requests stuck in "processing" (no
 * worker, lost job) are marked failed so they can be requested again.
 */
#[Signature('intelligence:refresh {--requisition= : Only this requisition (id or code)} {--limit= : Maximum open requisitions} {--dry-run : List what would be refreshed}')]
#[Description('Refresh Hiring Health, stale Talent Signals and the Hiring Risk Radar for open requisitions')]
class RefreshIntelligence extends Command
{
    public function handle(HiringHealthService $health, TalentSignalService $signals, HiringRiskRadar $radar, IntelligenceAiService $ai): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('intelligence.refresh.requisitions_per_run', 200)));
        $only = $this->option('requisition');

        $requisitions = RecruitmentRequisition::query()
            ->when($only, fn ($q) => is_numeric($only) ? $q->whereKey($only) : $q->where('code', $only), fn ($q) => $q->where('status', RequisitionStatus::Open))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($this->option('dry-run')) {
            $stale = $requisitions->reject(fn (RecruitmentRequisition $r) => $health->isFresh($health->currentFor($r)));
            $this->info("[dry run] {$requisitions->count()} requisition(s); {$stale->count()} with stale Hiring Health.");

            return self::SUCCESS;
        }

        $snapshots = 0;
        $refreshedSignals = 0;

        foreach ($requisitions as $requisition) {
            $before = $health->currentFor($requisition)?->id;
            $snapshots += $health->refresh($requisition)->id !== $before ? 1 : 0;
            $refreshedSignals += $signals->refreshForRequisition($requisition, (int) config('intelligence.refresh.signals_per_requisition', 200));
        }

        $risks = $radar->scan($only !== null ? $requisitions->first() : null, $limit);
        $expired = $ai->expireStaleRequests();

        $this->info("{$requisitions->count()} requisition(s): {$snapshots} health snapshot(s), {$refreshedSignals} talent signal(s) refreshed; risks {$risks['opened']} opened, {$risks['refreshed']} still open, {$risks['resolved']} resolved; {$expired} stale AI request(s) expired.");

        return self::SUCCESS;
    }
}
