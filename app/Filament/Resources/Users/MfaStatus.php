<?php

namespace App\Filament\Resources\Users;

use App\Models\User;
use App\Services\Identity\MfaService;

/**
 * Phase 8.4: a login's MFA status for display — Enabled, Pending (required but not enrolled yet),
 * Optional (not required, not set up).
 */
class MfaStatus
{
    public const string ENABLED = 'Enabled';

    public const string PENDING = 'Required — pending';

    public const string OPTIONAL = 'Not configured';

    public static function of(User $user): string
    {
        $mfa = app(MfaService::class);

        return match (true) {
            $mfa->isEnabledFor($user) => self::ENABLED,
            $mfa->isRequiredFor($user) => self::PENDING,
            default => self::OPTIONAL,
        };
    }

    public static function color(string $status): string
    {
        return match ($status) {
            self::ENABLED => 'success',
            self::PENDING => 'warning',
            default => 'gray',
        };
    }
}
