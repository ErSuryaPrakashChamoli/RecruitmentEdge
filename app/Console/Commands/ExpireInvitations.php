<?php

namespace App\Console\Commands;

use App\Services\Identity\TenantInvitationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-2: a tenant task (TenantTasks::QUEUED) — scheduled as `tenants:dispatch invitations:expire`,
 * so it runs inside one tenant at a time and expires only that tenant's invitations.
 */
#[Signature('invitations:expire')]
#[Description('Mark the tenant\'s pending invitations past their expiry as Expired (audited)')]
class ExpireInvitations extends Command
{
    public function handle(TenantInvitationService $invitations): int
    {
        $count = $invitations->expireDue();

        $this->info("Expired {$count} invitation(s).");

        return self::SUCCESS;
    }
}
