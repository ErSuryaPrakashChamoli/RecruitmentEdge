<?php

namespace App\Console\Commands;

use App\Services\Governance\GovernanceAuditor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 8.6 (D8.6-029): READ-ONLY governance report — master data, configuration drift, dependency
 * damage, effective ranges and historical-reproducibility limits. It never repairs, changes or
 * audits anything, so it is safe to run in production. Exits non-zero when an ERROR is found, so
 * it can gate a deployment check.
 */
#[Signature('governance:audit {--json : Print the findings as JSON}')]
#[Description('Report data-governance and configuration-integrity problems (read-only — never repairs data)')]
class GovernanceAudit extends Command
{
    public function handle(GovernanceAuditor $auditor): int
    {
        $findings = $auditor->run();
        $counts = collect($findings)->where('count', '>', 0)->countBy('level');

        if ($this->option('json')) {
            $this->line((string) json_encode(['findings' => $findings, 'errors' => $counts[GovernanceAuditor::ERROR] ?? 0, 'warnings' => $counts[GovernanceAuditor::WARNING] ?? 0], JSON_PRETTY_PRINT));
        } else {
            $this->line('Governance audit — read-only; nothing is repaired or changed.');
            $this->table(['Level', 'Area', 'Check', 'Count', 'Detail'], array_map(fn (array $finding) => array_values($finding), $findings));
            $this->line(sprintf('%d error(s), %d warning(s).', $counts[GovernanceAuditor::ERROR] ?? 0, $counts[GovernanceAuditor::WARNING] ?? 0));
        }

        return ($counts[GovernanceAuditor::ERROR] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
