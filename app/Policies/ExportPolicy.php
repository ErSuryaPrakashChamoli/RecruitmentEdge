<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Export\ExportGovernance;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Auth\Access\Response;

/**
 * Phase 8.8 (SEC-88-03): who may download a Filament export file. Filament's download controller
 * consults this policy when one is registered. Only the user who ran the export, and only within
 * ExportGovernance::DOWNLOAD_WINDOW_HOURS of completion; a suspended or separated user is already
 * refused by the Gate::before staff-access rule. Exports are never listed or edited in the app.
 */
class ExportPolicy
{
    public function view(User $user, Export $export): Response
    {
        if (! $export->user()->is($user)) {
            return Response::deny('Only the person who ran this export can download it.');
        }

        if (ExportGovernance::isDownloadExpired($export)) {
            return Response::deny('This export can no longer be downloaded. Run the export again.');
        }

        return Response::allow();
    }
}
