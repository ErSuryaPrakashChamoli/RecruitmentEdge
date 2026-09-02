<?php

namespace App\Services;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * The only code path allowed to write into the persistent (database) Notification Center —
 * every proactive alert (Section 40) goes through here rather than each caller building its own
 * Filament\Notifications\Notification. Category is conveyed via a `[Category] ...` title prefix +
 * color rather than a bespoke column, so the panel's native database-notifications UI (bell,
 * unread count, mark as read) needs no custom rendering.
 */
class NotificationDispatchService
{
    /**
     * @param  Model|null  $recipient  No-ops when null — not every Employee has a User account
     *                                 (e.g. an interviewer who isn't a system user).
     */
    public function alert(
        ?Model $recipient,
        string $category,
        string $title,
        string $body,
        string $color = 'warning',
        ?string $url = null,
        ?string $dedupeKey = null,
    ): void {
        if ($recipient === null) {
            return;
        }

        if ($dedupeKey !== null && $this->alreadySent($recipient, $dedupeKey)) {
            return;
        }

        Notification::make()
            ->title("[{$category}] {$title}")
            ->body($body)
            ->color($color)
            ->icon($this->iconFor($color))
            ->when($dedupeKey !== null, fn (Notification $notification) => $notification->viewData(['dedupeKey' => $dedupeKey]))
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
