<?php

namespace App\Console\Commands;

use App\Services\Lifecycle\LifecycleAuditor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 8.3: READ-ONLY lifecycle integrity report. It never repairs or changes data. ERROR marks
 * facts that are inconsistent under any schema version; WARNING marks states Phase 8.3 prevents
 * that older data may legitimately contain; INFO gives context. Exits non-zero when an ERROR is
 * found, so it can gate a deployment check.
 */
#[Signature('lifecycle:audit
    {--from= : Only applications changed on or after this date (YYYY-MM-DD)}
    {--to= : Only applications changed on or before this date (YYYY-MM-DD)}
    {--requisition= : Only this requisition id}
    {--application= : Only this application id}
    {--limit=50 : Maximum findings per check}')]
#[Description('Report lifecycle integrity problems (read-only — never repairs data)')]
class LifecycleAudit extends Command
{
    public function handle(LifecycleAuditor $auditor): int
    {
        try {
            $from = filled($this->option('from')) ? CarbonImmutable::parse((string) $this->option('from'))->startOfDay() : null;
            $to = filled($this->option('to')) ? CarbonImmutable::parse((string) $this->option('to'))->endOfDay() : null;
        } catch (Throwable) {
            $this->error('--from and --to must be dates (YYYY-MM-DD).');

            return self::FAILURE;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $findings = $auditor->run(
            $from,
            $to,
            filled($this->option('requisition')) ? (int) $this->option('requisition') : null,
            filled($this->option('application')) ? (int) $this->option('application') : null,
            $limit,
        );

        $this->line('Lifecycle audit — read-only; nothing is repaired or changed.');
        $this->table(['Level', 'Check', 'Record', 'Detail'], array_map(fn (array $finding) => array_values($finding), $findings));

        $counts = collect($findings)->countBy('level');
        $this->line(sprintf('%d error(s), %d warning(s), %d info. At most %d finding(s) per check are listed.', $counts[LifecycleAuditor::ERROR] ?? 0, $counts[LifecycleAuditor::WARNING] ?? 0, $counts[LifecycleAuditor::INFO] ?? 0, $limit));

        return ($counts[LifecycleAuditor::ERROR] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
