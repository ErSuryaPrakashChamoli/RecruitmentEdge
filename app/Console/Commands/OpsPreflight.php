<?php

namespace App\Console\Commands;

use App\Services\Operations\ProductionPreflight;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-7 (C13): validates the production configuration (ProductionPreflight). Exits non-zero when a
 * blocker is found in production — docker/entrypoint.sh runs it before serving and stops the
 * container (PREFLIGHT_ENFORCE=false is the documented emergency override). Prints check names
 * and messages only, never configuration values.
 */
#[Signature('ops:preflight {--json : Print the result as JSON} {--strict : Fail on warnings too}')]
#[Description('Check that the configuration is safe for production (keys, debug, cache, queue, mail, cookies, audit)')]
class OpsPreflight extends Command
{
    public function handle(ProductionPreflight $preflight): int
    {
        $problems = $preflight->problems();
        $failed = ProductionPreflight::hasBlockers($problems) || ($this->option('strict') && $problems !== []);

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => ! $failed, 'problems' => $problems], JSON_PRETTY_PRINT));
        } elseif ($problems === []) {
            $this->info('Preflight: no problems.');
        } else {
            $this->table(['Level', 'Check', 'Problem'], collect($problems)->map(fn (array $problem): array => [$problem['level'], $problem['check'], $problem['message']])->all());
        }

        Log::log($failed ? 'error' : ($problems === [] ? 'info' : 'warning'), 'ops.preflight', ['ok' => ! $failed, 'problems' => ProductionPreflight::summary($problems)]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
