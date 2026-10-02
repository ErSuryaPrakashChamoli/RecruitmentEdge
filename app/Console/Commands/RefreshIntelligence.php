<?php

namespace App\Console\Commands;

use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Services\Intelligence\HiringHealthService;
use App\Services\Intelligence\HiringRiskRadar;
use App\Services\Intelligence\IntelligenceAiService;
use App\Services\Intelligence\TalentSignalService;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EDGE Intelligence refresh (Phase 7), hourly: Hiring Health snapshots for open requisitions whose
 * snapshot is stale, stale Talent Signals for their active applications, then the Risk Radar scan
 * (which also auto-resolves risks no longer observed). Deterministic, bounded, idempotent within
 * each snapshot's freshness window — no AI is called here. AI requests stuck in "processing" (no
 * worker, lost job) are marked failed so they can be requested again.
 */
#[Signature('intelligence:refresh {--requisition= : Only this requisition (id or code)} {--limit= : Only the first N open requisitions (manual runs; the Risk Radar still scans all)} {--dry-run : List what would be refreshed}')]
#[Description('Refresh Hiring Health, stale Talent Signals and the Hiring Risk Radar for open requisitions')]
class RefreshIntelligence extends Command
{
    public function handle(HiringHealthService $health, TalentSignalService $signals, HiringRiskRadar $radar, IntelligenceAiService $ai): int
    {
        $only = $this->option('requisition');
        // Phase 8.7 (D8.7-027): every open requisition, in chunks — --limit is for manual runs only.
        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;

        $query = RecruitmentRequisition::query()
            ->when($only, fn ($q) => is_numeric($only) ? $q->whereKey($only) : $q->where('code', $only), fn ($q) => $q->where('status', RequisitionStatus::Open))
            ->orderBy('id');

        if ($this->option('dry-run')) {
            $requisitions = $query->when($limit, fn ($q) => $q->limit($limit))->get();
            $stale = $requisitions->reject(fn (RecruitmentRequisition $r) => $health->isFresh($health->currentFor($r)));
            $this->info("[dry run] {$requisitions->count()} requisition(s); {$stale->count()} with stale Hiring Health.");

            return self::SUCCESS;
        }

        [$count, $snapshots, $refreshedSignals, $failed] = [0, 0, 0, 0];
        $perRequisition = (int) config('intelligence.refresh.signals_per_requisition', 200);

        $refresh = function (RecruitmentRequisition $requisition) use ($health, $signals, $perRequisition, &$count, &$snapshots, &$refreshedSignals, &$failed): void {
            // One requisition's failure never stops the others.
            try {
                $before = $health->currentFor($requisition)?->id;
                $snapshots += $health->refresh($requisition)->id !== $before ? 1 : 0;
                $refreshedSignals += $signals->refreshForRequisition($requisition, $perRequisition);
                $count++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        };

        $deferredIds = [];

        if ($limit !== null || $only !== null) {
            $query->when($limit, fn ($q) => $q->limit($limit))->get()->each($refresh);
        } else {
            $deferredIds = $this->refreshStalestFirst($refresh);
        }

        $deferred = count($deferredIds);

        $scoped = $only !== null ? RecruitmentRequisition::query()->when(is_numeric($only), fn ($q) => $q->whereKey($only), fn ($q) => $q->where('code', $only))->first() : null;
        $risks = $only !== null && $scoped === null ? ['opened' => 0, 'refreshed' => 0, 'resolved' => 0] : $radar->scan($scoped, $deferredIds);
        $expired = $ai->expireStaleRequests();

        if ($deferred > 0) {
            // Not silent: the next hourly run starts with these (they are the least stale left).
            Log::warning('intelligence.refresh_deferred', ['deferred' => $deferred, 'budget_seconds' => (int) config('intelligence.refresh.time_budget_seconds')]);
        }

        $this->info("{$count} requisition(s): {$snapshots} health snapshot(s), {$refreshedSignals} talent signal(s) refreshed".($failed > 0 ? ", {$failed} failed" : '').($deferred > 0 ? ", {$deferred} deferred to the next run (time budget)" : '')
            .'; risks '.(($risks['skipped'] ?? false) ? 'skipped (another scan is running)' : "{$risks['opened']} opened, {$risks['refreshed']} still open, {$risks['resolved']} resolved")
            ."; {$expired} stale AI request(s) expired.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Phase 8.9 (P89-PERF-005): a scheduled run refreshes the open requisitions stalest first — no
     * Hiring Health snapshot yet, then the oldest — and stops starting new ones once the run's time
     * budget is spent, so a cold run at thousands of open requisitions no longer overruns its hourly
     * cadence (414 s cold at 520 in Phase 8.7, ≈ 0.8 s each). What is left is deferred, counted and
     * logged, and taken first by the next run; the Risk Radar leaves their requisition risks as they
     * are this run. Returns the deferred requisition ids.
     *
     * @param  Closure(RecruitmentRequisition): void  $refresh
     * @return array<int, int>
     */
    private function refreshStalestFirst(Closure $refresh): array
    {
        $deadline = microtime(true) + (int) config('intelligence.refresh.time_budget_seconds', 2700);

        $ids = RecruitmentRequisition::query()
            ->where('recruitment_requisitions.status', RequisitionStatus::Open)
            ->leftJoin('hiring_health_snapshots as current_health', function ($join): void {
                $join->on('current_health.requisition_id', '=', 'recruitment_requisitions.id')->where('current_health.is_current', true);
            })
            ->orderByRaw('current_health.computed_at is not null')
            ->orderBy('current_health.computed_at')
            ->orderBy('recruitment_requisitions.id')
            ->pluck('recruitment_requisitions.id')
            ->unique()
            ->values();

        foreach ($ids->chunk(HiringRiskRadar::CHUNK) as $index => $chunk) {
            $requisitions = RecruitmentRequisition::query()->whereKey($chunk->all())->get()->keyBy('id');

            foreach ($chunk as $position => $id) {
                if (microtime(true) >= $deadline) {
                    return $ids->slice($position)->map(fn ($id): int => (int) $id)->values()->all();
                }

                if (($requisition = $requisitions->get($id)) !== null) {
                    $refresh($requisition);
                }
            }
        }

        return [];
    }
}
