<?php

namespace App\Services\Export;

use App\Models\AuditLog;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Livewire\Component;

/**
 * Phase 8.8 (SEC-88-03, D8.8-EXPORT-001 as decided on 2026-10-01): the controls every Filament
 * table export runs under. Approved: at most 10,000 rows per export, downloads only by the
 * owner within 24 hours of completion, behind the panel's staff-access and MFA checks, and an
 * audit row for every export request (started or refused) and every download.
 *
 * Not decided, so not implemented: stored-file expiry (a retention period — deferred with
 * SEC-88-02), role split, export reason, approval and rate limits.
 */
class ExportGovernance
{
    public const int MAX_ROWS = 10_000;

    public const int DOWNLOAD_WINDOW_HOURS = 24;

    private const string REQUEST_KEY = 'export.request';

    private const string STARTED_KEY = 'export.started';

    /**
     * Before the export action runs: remember what was asked for, so the audit row written when
     * the export starts (or is refused) records it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function rememberRequest(ExportAction $action, array $data, Component $livewire): void
    {
        $columns = collect($data['columnMap'] ?? [])
            ->filter(fn (mixed $column): bool => is_array($column) && (bool) ($column['isEnabled'] ?? false))
            ->keys()
            ->values()
            ->all();

        $filters = property_exists($livewire, 'tableFilters') && is_array($livewire->tableFilters)
            ? array_filter(Arr::dot($livewire->tableFilters), fn (mixed $value): bool => filled($value))
            : [];

        $search = property_exists($livewire, 'tableSearch') ? (string) $livewire->tableSearch : '';

        Context::addHidden(self::REQUEST_KEY, array_filter([
            'exporter' => class_basename($action->getExporter()),
            'columns' => $columns,
            'filters' => $filters,
            'search' => $search,
        ], fn (mixed $value): bool => filled($value)));
        Context::forgetHidden(self::STARTED_KEY);
    }

    /**
     * An export was created (the request passed the row cap): audit it on the export itself.
     */
    public static function recordRequested(Export $export): void
    {
        AuditLog::record($export, 'export_requested', null, [
            ...(array) Context::getHidden(self::REQUEST_KEY, []),
            'exporter' => class_basename($export->exporter),
            'rows' => $export->total_rows,
        ]);

        Context::addHidden(self::STARTED_KEY, true);
    }

    /**
     * After the export action: a remembered request that never became an export was refused
     * (over the row cap) — audit the attempt on the requesting user.
     */
    public static function recordRefusedIfNotStarted(): void
    {
        $request = Context::getHidden(self::REQUEST_KEY);
        $started = (bool) Context::getHidden(self::STARTED_KEY, false);

        Context::forgetHidden(self::REQUEST_KEY);
        Context::forgetHidden(self::STARTED_KEY);

        $user = auth()->user();

        if ($request === null || $started || $user === null) {
            return;
        }

        AuditLog::record($user, 'export_refused', null, [...(array) $request, 'reason' => 'row_cap', 'max_rows' => self::MAX_ROWS]);
    }

    /**
     * The moment after which an export can no longer be downloaded.
     */
    public static function downloadExpiresAt(Export $export): Carbon
    {
        $completedAt = $export->completed_at;
        $from = $completedAt !== null ? Carbon::createFromTimestamp((int) $completedAt) : Carbon::parse($export->created_at);

        return $from->copy()->addHours(self::DOWNLOAD_WINDOW_HOURS);
    }

    public static function isDownloadExpired(Export $export): bool
    {
        return self::downloadExpiresAt($export)->isPast();
    }
}
