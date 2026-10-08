<?php

namespace App\Console\Commands;

use App\Services\Identity\IdentityAuditor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 8.4: READ-ONLY identity integrity report (access versus employment, CHRO coverage, role
 * keys, hierarchy, stale AI actions, automation ownership, MFA coverage, open handoffs). Ids only;
 * never repairs anything. Exits non-zero on an ERROR, so it can gate a deployment check.
 */
#[Signature('identity:audit {--limit=50 : Maximum findings per check}')]
#[Description('Report identity and access integrity problems (read-only — never repairs data)')]
class IdentityAudit extends Command
{
    public function handle(IdentityAuditor $auditor): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $findings = $auditor->run($limit);

        $this->line('Identity audit — read-only; nothing is repaired or changed.');
        $this->table(['Level', 'Check', 'Record', 'Detail'], array_map(fn (array $finding) => array_values($finding), $findings));

        $counts = collect($findings)->countBy('level');
        $this->line(sprintf('%d error(s), %d warning(s), %d info.', $counts[IdentityAuditor::ERROR] ?? 0, $counts[IdentityAuditor::WARNING] ?? 0, $counts[IdentityAuditor::INFO] ?? 0));

        return ($counts[IdentityAuditor::ERROR] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
