<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\PlatformOperator;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-2: platform staff grant, revoke and list platform roles (never a tenant role). Platform
 * level: runs with no tenant; audited in the platform stream.
 */
#[Signature('platform:operator
    {action : grant, revoke or list}
    {email? : The identity\'s email address}
    {role? : administrator, support or compliance}
    {--reason= : Why (required to grant or revoke)}')]
#[Description('Grant, revoke or list platform operator roles (no tenant access is ever implied)')]
class PlatformOperatorCommand extends Command
{
    public function handle(PlatformAccess $platform): int
    {
        if ($this->argument('action') === 'list') {
            $this->table(['user id', 'role', 'granted', 'revoked'], PlatformOperator::query()->orderBy('user_id')->get()
                ->map(fn (PlatformOperator $operator): array => [$operator->user_id, $operator->role->value, $operator->granted_at?->toDateTimeString(), $operator->revoked_at?->toDateTimeString() ?? '—'])->all());

            return self::SUCCESS;
        }

        $user = User::query()->whereEmailIs((string) $this->argument('email'))->first();
        $role = PlatformRole::tryFrom((string) $this->argument('role'));

        if ($user === null || $role === null || ! in_array($this->argument('action'), ['grant', 'revoke'], true)) {
            $this->error('Usage: platform:operator grant|revoke <email> administrator|support|compliance --reason="…"');

            return self::FAILURE;
        }

        try {
            $this->argument('action') === 'grant'
                ? $platform->grant($user, $role, (string) $this->option('reason'))
                : $platform->revoke($user, $role, (string) $this->option('reason'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Platform role {$role->value} ".($this->argument('action') === 'grant' ? 'granted to' : 'revoked from')." user #{$user->id}.");

        return self::SUCCESS;
    }
}
