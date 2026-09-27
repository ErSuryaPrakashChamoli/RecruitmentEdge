<?php

namespace App\Services;

use App\Models\User;
use App\Services\Identity\StaffAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Phase 8.7 (D8.7-028): platform failures — failed jobs, stuck work, a silent scheduler, a failed
 * ownership handoff — are raised as in-app alerts to the people who run the platform (holders of
 * settings.manage with current access). One alert per problem per hour: the same key within the
 * hour is not repeated. Alerts carry counts and names of work types, never personal data.
 */
class PlatformAlertService
{
    public const string PERMISSION = 'settings.manage';

    public function __construct(
        private readonly NotificationDispatchService $notifications,
        private readonly StaffAccessService $access,
    ) {}

    /**
     * Returns the number of people alerted.
     */
    public function raise(string $key, string $title, string $body, ?string $url = null): int
    {
        $recipients = $this->recipients();

        foreach ($recipients as $recipient) {
            $this->notifications->alert($recipient, 'Platform', $title, $body, 'danger', $url, "platform:{$key}:".now()->format('YmdH'));
        }

        Log::warning('platform.alert', ['key' => $key, 'recipients' => $recipients->count()]);

        return $recipients->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        try {
            return User::permission(self::PERMISSION)->get()->filter(fn (User $user): bool => $this->access->permits($user))->values();
        } catch (PermissionDoesNotExist) {
            return collect();
        }
    }
}
