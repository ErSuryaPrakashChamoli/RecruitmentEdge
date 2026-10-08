<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.8 (SEC-88-13): every served download of a Filament export file is audited on the export
 * (who, which export, which format). Runs in the `filament.actions` group, after the staff-access
 * and MFA checks.
 */
class AuditExportDownload
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $export = $request->route('export');

        if ($request->routeIs('filament.exports.download') && $export instanceof Export && $response->isSuccessful()) {
            AuditLog::record($export, 'export_downloaded', null, [
                'exporter' => class_basename($export->exporter),
                'format' => (string) $request->query('format'),
            ]);
        }

        return $response;
    }
}
