<?php

namespace App\Services;

use App\Enums\ActionPriority;
use App\Models\User;
use App\Notifications\StaffDatabaseNotification;
use App\Services\Automation\RecipientResolver;
use App\Services\Identity\StaffAccessService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The only code path allowed to write into the persistent (database) Notification Center —
 * every proactive alert (Section 40) goes through here rather than each caller building its own
 * Filament\Notifications\Notification. Category is conveyed via a `[Category] ...` title prefix +
 * color rather than a bespoke column, so the panel's native database-notifications UI (bell,
 * unread count, mark as read) needs no custom rendering.
 *
 * Phase 6: the Notification Center page reads the same table. `priority` and `meta` (source
 * automation rule/execution, related entity) are stored in viewData alongside the category, so the
 * centre can filter and explain notifications without a second notification store.
 */
class NotificationDispatchService
{
    /**
     * @param  Model|null  $recipient  No-ops when null — not every Employee has a User account
     *                                 (e.g. an interviewer who isn't a system user).
     * @param  array{rule_id?: int, execution_id?: int, rule_name?: string, entity_type?: string, entity_id?: int}  $meta
     */
    public function alert(
        ?Model $recipient,
        string $category,
        string $title,
        string $body,
        string $color = 'warning',
        ?string $url = null,
        ?string $dedupeKey = null,
        ?ActionPriority $priority = null,
        array $meta = [],
    ): void {
        $recipient = $this->operationalRecipient($recipient);

        if ($recipient === null) {
            return;
        }

        // Phase 8.7 (P83-BACKLOG-010): the database row is written by a queued job, so two alerts
        // dispatched together would both pass the table check — claim the key atomically first.
        if ($dedupeKey !== null && ($this->alreadySent($recipient, $dedupeKey) || ! Cache::add('alert-dedupe:'.$recipient->getKey().':'.sha1($dedupeKey), true, now()->addDay()))) {
            return;
        }

        $priority ??= ActionPriority::fromColor($color);

        $notification = Notification::make()
            ->title("[{$category}] {$title}")
            ->body($body)
            ->color($color)
            ->icon($this->iconFor($color))
            ->viewData(array_filter([
                'dedupeKey' => $dedupeKey,
                'category' => $category,
                'priority' => $priority->value,
                ...$meta,
            ], fn ($value) => $value !== null))
            ->when($url !== null, fn (Notification $notification) => $notification->actions([
                Action::make('view')->button()->url($url)->markAsRead(),
            ]));

        // Phase 8.7 (D8.7-001/017): encrypted, on the `notifications` queue.
        $recipient->notify(new StaffDatabaseNotification($notification->getDatabaseMessage()));
    }

    /**
     * Phase 8.4: operational alerts go only to people who can act on them. A staff recipient who is
     * no longer reachable (inactive, separated or deleted employee; suspended or revoked login) is
     * replaced by the nearest reachable manager — who sees that person's work through the
     * hierarchy — and failing that by an HR administrator; with nobody reachable the alert is
     * dropped and logged. A current recipient is unchanged.
     */
    private function operationalRecipient(?Model $recipient): ?Model
    {
        if (! $recipient instanceof User) {
            return $recipient;
        }

        $employee = $recipient->employee()->withTrashed()->first();
        $resolver = app(RecipientResolver::class);

        if ($employee === null ? app(StaffAccessService::class)->permits($recipient) : $resolver->isReachable($employee)) {
            return $recipient;
        }

        $actions = app(RecruiterActionService::class);
        $replacement = ($employee !== null ? $actions->reachableOwner($employee) : null) ?? $actions->fallbackOwner();

        Log::info('identity.notification_rerouted', ['from_user_id' => $recipient->id, 'to_user_id' => $replacement?->user?->id]);

        return $replacement?->user;
    }

    private function alreadySent(Model $recipient, string $dedupeKey): bool
    {
        if (! method_exists($recipient, 'notifications')) {
            return false;
        }

        return $recipient->notifications()
            ->where('data->viewData->dedupeKey', $dedupeKey)
            ->exists();
    }

    private function iconFor(string $color): string
    {
        return match ($color) {
            'success' => 'heroicon-o-check-circle',
            'danger' => 'heroicon-o-exclamation-circle',
            'info' => 'heroicon-o-information-circle',
            default => 'heroicon-o-exclamation-triangle',
        };
    }
}
