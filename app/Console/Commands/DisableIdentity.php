<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Platform\PlatformIdentityService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-2: platform staff lock (or unlock) a whole identity — every tenant at once, sessions ended.
 * A platform decision, never a tenant's; tenants suspend or revoke their own membership instead.
 */
#[Signature('identity:disable
    {email : The identity\'s email address}
    {--enable : Lift the lock instead}
    {--reason= : Why (required)}')]
#[Description('Disable (or --enable) a staff identity platform-wide')]
class DisableIdentity extends Command
{
    public function handle(PlatformIdentityService $identities): int
    {
        $user = User::query()->whereEmailIs((string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No identity uses that address.');

            return self::FAILURE;
        }

        try {
            $this->option('enable') ? $identities->enable($user, (string) $this->option('reason')) : $identities->disable($user, (string) $this->option('reason'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Identity #'.$user->id.($this->option('enable') ? ' enabled.' : ' disabled; every session ended.'));

        return self::SUCCESS;
    }
}
