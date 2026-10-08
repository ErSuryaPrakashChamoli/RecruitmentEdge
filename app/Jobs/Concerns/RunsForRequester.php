<?php

namespace App\Jobs\Concerns;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.7 (D8.7-015/016, SEC-87-08): a queued AI job works for the person who asked. When it
 * runs, that person is reloaded; if their access has since been suspended, revoked or removed,
 * the job does nothing and records why (never on authority they no longer have). Otherwise the
 * work runs as the `ai` actor on their behalf.
 */
trait RunsForRequester
{
    /**
     * @param  callable(?User): mixed  $work
     */
    protected function runForRequester(?int $userId, Model $subject, callable $work): void
    {
        $requester = $userId !== null ? User::query()->find($userId) : null;

        if ($userId !== null && ($requester === null || ! app(StaffAccessService::class)->permits($requester))) {
            AuditLog::asActor('ai', null, fn () => AuditLog::record($subject, 'ai_request_skipped', null, ['reason' => 'The requester no longer has access.', 'requested_by_user_id' => $userId, 'job' => static::class]));

            return;
        }

        AuditLog::asActor('ai', $requester?->id, fn () => $work($requester));
    }

    protected function recordAiFailure(Model $subject, string $action): void
    {
        AuditLog::asActor('ai', null, fn () => AuditLog::record($subject, $action, null, ['job' => static::class]));
    }
}
