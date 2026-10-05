<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantDeletionRequest;
use App\Models\User;
use App\Services\Platform\TenantDeletionService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-5: the tenant deletion workflow on the command line, as a named platform operator (the
 * same rules as the platform panel: platform.deletion.manage, a cancelled tenant, a second operator
 * to approve, the grace period before a purge).
 */
#[Signature('tenants:deletion
    {action : request, approve, cancel, purge or status}
    {slug : The tenant}
    {--operator= : The platform operator\'s email (required except for status)}
    {--reason= : Why (request and cancel)}')]
#[Description('Request, approve, cancel or start the purge of a tenant deletion (platform)')]
class TenantsDeletion extends Command
{
    public function handle(TenantDeletionService $deletions): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        $open = TenantDeletionRequest::query()->where('tenant_id', $tenant->id)->where('is_open', true)->first();
        $action = (string) $this->argument('action');

        if ($action === 'status') {
            $this->line($open === null ? "{$tenant->slug} ({$tenant->status->value}): no open deletion." : "{$tenant->slug} ({$tenant->status->value}): deletion #{$open->id} {$open->status->value}".($open->purge_after !== null ? ", purge after {$open->purge_after->toIso8601String()}" : '').'.');

            return self::SUCCESS;
        }

        $operator = User::query()->where('email', strtolower(trim((string) $this->option('operator'))))->first();
        $reason = (string) $this->option('reason');

        try {
            if ($operator === null) {
                throw new DomainException('Name the platform operator with --operator=<email>.');
            }

            $message = match ($action) {
                'request' => 'Deletion #'.$deletions->request($tenant, $reason, $operator)->id.' requested; another operator approves it.',
                'approve' => 'Approved: purge after '.$deletions->approve($open ?? throw new DomainException('No open deletion.'), $operator)->purge_after->toIso8601String().'.',
                'cancel' => 'Deletion #'.$deletions->cancel($open ?? throw new DomainException('No open deletion.'), $reason, $operator)->id.' cancelled.',
                'purge' => (function () use ($deletions, $open, $operator): string {
                    $deletions->purgeNow($open ?? throw new DomainException('No open deletion.'), $operator);

                    return 'Purge queued.';
                })(),
                default => throw new DomainException('Unknown action.'),
            };
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($message);

        return self::SUCCESS;
    }
}
