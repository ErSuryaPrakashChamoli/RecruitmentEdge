<?php

namespace App\Console\Commands;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Identity\LastChroProtectedException;
use App\Services\Identity\StaffAccessService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 8.4 backfill: brings existing logins into line with employment, deterministically and
 * through the services (audited, one person at a time, idempotent). A dry run (the default) only
 * reports. Mapping: separation taken effect → applied (employment Separated, access Revoked);
 * employee Inactive → access Suspended; employee deleted → access Suspended. The last effective
 * CHRO is never touched (reported). Nothing is ever restored.
 */
#[Signature('identity:reconcile-access {--execute : Apply the changes (default: dry run)}')]
#[Description('Map existing logins to the access their employment allows (dry run by default)')]
class ReconcileAccess extends Command
{
    public function handle(EmployeeLifecycleService $lifecycle, StaffAccessService $access): int
    {
        $execute = (bool) $this->option('execute');
        $separations = $lifecycle->enforceDueSeparations(dryRun: ! $execute);
        $counts = ['separations_due' => $separations['due'], 'separations_applied' => $separations['applied'], 'to_suspend' => 0, 'suspended' => 0, 'protected' => $separations['protected'], 'failed' => $separations['failed']];

        User::query()->where('access_status', AccessState::Active->value)->whereNotNull('employee_id')
            ->with(['employee' => fn ($query) => $query->withTrashed()])
            ->lazyById(500)
            ->filter(fn (User $user) => $user->employee !== null && ($user->employee->trashed() || $user->employee->status === EmployeeStatus::Inactive))
            ->each(function (User $user) use ($access, $execute, &$counts): void {
                $counts['to_suspend']++;

                if (! $execute) {
                    return;
                }

                try {
                    $access->suspend($user, null, $user->employee->trashed() ? 'Employee record deleted (reconciliation)' : 'Employment inactive (reconciliation)', 'employment');
                    $counts['suspended']++;
                } catch (LastChroProtectedException) {
                    $counts['protected']++;
                } catch (Throwable $e) {
                    $counts['failed']++;
                    report($e);
                }
            });

        $this->line($execute ? 'Access reconciliation — applied:' : 'Access reconciliation — DRY RUN (nothing changed; add --execute to apply):');
        $this->table(['What', 'Count'], collect($counts)->map(fn (int $count, string $what) => [str_replace('_', ' ', $what), $count])->values()->all());

        if ($counts['protected'] > 0) {
            $this->warn('Some changes were not made because they would remove the last active CHRO.');
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
