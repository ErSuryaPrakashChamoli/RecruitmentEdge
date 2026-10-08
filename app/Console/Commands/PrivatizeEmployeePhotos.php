<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SaaS-5 (S1-06): moves the current tenant's employee photos from the public disk to the private
 * one, keeping each path (the rows do not change). Idempotent and safe to interrupt: a photo is
 * copied (beside its target, then moved into place), the copy's size checked, and only then the
 * public file removed; a photo already moved is skipped (a leftover public copy is removed, a
 * partial private copy replaced). Run it in every tenant once after deploying
 * SaaS-5: tenants:run files:privatize-employee-photos --all. Until then photos are read from where
 * they are (Employee::photoDisk).
 */
#[Signature('files:privatize-employee-photos {--dry-run : Report what would move}')]
#[Description('Move the current tenant\'s employee photos to private storage (tenant task)')]
class PrivatizeEmployeePhotos extends Command
{
    public function handle(): int
    {
        TenantContext::current()->requireId();
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $counts = ['moved' => 0, 'already_private' => 0, 'missing' => 0, 'failed' => 0];

        Employee::query()->withTrashed()->whereNotNull('photo_path')->where('photo_path', '!=', '')->select(['id', 'tenant_id', 'photo_path'])->lazyById(500)
            ->each(function (Employee $employee) use ($public, $private, &$counts): void {
                $path = (string) $employee->photo_path;
                $onPrivate = $private->exists($path);
                $onPublic = $public->exists($path);

                if (! $onPublic) {
                    $counts[$onPrivate ? 'already_private' : 'missing']++;

                    return;
                }

                if ($this->option('dry-run')) {
                    $counts[$onPrivate ? 'already_private' : 'moved']++;

                    return;
                }

                // Copied unless an intact copy is already private; a partial copy left by an interrupted
                // run is replaced. Written beside the target, then moved into place, so the photo's
                // path never holds a partial file.
                if (! $onPrivate || $private->size($path) !== $public->size($path)) {
                    $part = $path.'.part';
                    $stream = $public->readStream($path);
                    $private->put($part, $stream);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    $private->delete($path);
                    $private->move($part, $path);
                }

                if (! $private->exists($path) || $private->size($path) !== $public->size($path)) {
                    $counts['failed']++;
                    Log::error('files.photo_move_failed', ['employee_id' => $employee->id]);

                    return;
                }

                $public->delete($path);
                $counts[$onPrivate ? 'already_private' : 'moved']++;
            });

        $this->line(collect($counts)->map(fn (int $count, string $key): string => "{$key}: {$count}")->implode(', ').($this->option('dry-run') ? ' (dry run)' : ''));
        Log::info('files.photos_privatized', ['tenant_id' => TenantContext::current()->id(), ...$counts]);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
