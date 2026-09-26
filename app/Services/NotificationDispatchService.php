<?php

namespace App\Services;

use App\Enums\ActionPriority;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

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
        if ($recipient === null) {
            return;
        }

        if ($dedupeKey !== null && $this->alreadySent($recipient, $dedupeKey)) {
            return;
        }

        $priority ??= ActionPriority::fromColor($color);

        Notification::make()
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
            ]))
            ->sendToDatabase($recipient);
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
