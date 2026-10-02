<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use League\Flysystem\FileAttributes;

/**
 * Phase 8.9 (P89-OPS / D8.9-017, storage scope): what the stored files are — size per area for
 * growth tracking, files no record references (orphans: a deleted row leaves its file; a rolled-back
 * upload leaves its file), and records whose file is missing (a restore without the matching files).
 *
 * Read-only: it never deletes, moves or rewrites a file or a row — removing orphans is a retention
 * decision (SEC-88-02, deferred). Memory stays bounded: files are listed lazily and checked against
 * the database 1,000 at a time; records are walked by id.
 */
#[Signature('storage:audit {--json : Print the report as JSON} {--list : Also print each unreferenced and missing path}')]
#[Description('Report stored-file size, unreferenced files and records with missing files (read-only — never deletes)')]
class StorageAudit extends Command
{
    /**
     * Every column that holds a stored file's path, by disk. A soft-deleted row still references its file.
     *
     * @var array<int, array{table: string, column: string, disk: string, disk_column?: string}>
     */
    public const array REFERENCES = [
        ['table' => 'candidates', 'column' => 'resume_path', 'disk' => 'local'],
        ['table' => 'candidate_documents', 'column' => 'file_path', 'disk' => 'local'],
        ['table' => 'ai_documents', 'column' => 'file_path', 'disk' => 'local', 'disk_column' => 'disk'],
        ['table' => 'offer_letters', 'column' => 'file_path', 'disk' => 'local'],
        ['table' => 'offer_letter_conversions', 'column' => 'document_path', 'disk' => 'local'],
        ['table' => 'offer_letter_templates', 'column' => 'file_path', 'disk' => 'local'],
        ['table' => 'offer_letter_template_versions', 'column' => 'file_path', 'disk' => 'local'],
        ['table' => 'imports', 'column' => 'file_path', 'disk' => 'local'],
        ['table' => 'employees', 'column' => 'photo_path', 'disk' => 'public'],
    ];

    /**
     * Working areas whose files are removed by their own process (Livewire's upload cleanup, the
     * interviewer import job); counted, never called orphans.
     *
     * @var array<int, string>
     */
    public const array TRANSIENT_AREAS = ['livewire-tmp', 'interviewer-imports'];

    private const int BATCH = 1000;

    /**
     * @var array<string, array{disk: string, area: string, files: int, bytes: int, unreferenced_files: int, unreferenced_bytes: int, transient: bool}>
     */
    private array $areas = [];

    public function handle(): int
    {
        foreach (['local', 'public'] as $disk) {
            $this->auditDisk($disk);
        }

        $missing = collect(self::REFERENCES)->map(fn (array $reference): array => $this->missingFiles($reference))->all();

        if ($this->option('json')) {
            $this->line((string) json_encode(['areas' => array_values($this->areas), 'references' => $missing, 'deleted' => 0], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Disk', 'Area', 'Files', 'Size', 'Unreferenced files', 'Unreferenced size'],
            collect($this->areas)->map(fn (array $area): array => [
                $area['disk'], $area['area'], $area['files'], Number::fileSize($area['bytes']),
                $area['transient'] ? 'transient' : $area['unreferenced_files'],
                $area['transient'] ? '—' : Number::fileSize($area['unreferenced_bytes']),
            ])->values()->all(),
        );
        $this->table(
            ['Reference', 'Records with a file', 'File missing'],
            collect($missing)->map(fn (array $row): array => [$row['reference'], $row['records'], $row['missing']])->all(),
        );
        $this->info('Read-only: no file or record was changed.');

        return self::SUCCESS;
    }

    private function auditDisk(string $disk): void
    {
        $pending = [];

        foreach (Storage::disk($disk)->getDriver()->listContents('', true) as $item) {
            if (! $item instanceof FileAttributes || str_starts_with(basename($item->path()), '.')) {
                continue;
            }

            $path = $item->path();
            $bytes = (int) ($item->fileSize() ?? Storage::disk($disk)->size($path));
            $area = &$this->area($disk, $this->areaOf($path));
            $area['files']++;
            $area['bytes'] += $bytes;
            unset($area);

            if (! in_array($this->areaOf($path), self::TRANSIENT_AREAS, true)) {
                $pending[$path] = $bytes;
            }

            if (count($pending) >= self::BATCH) {
                $this->checkReferenced($disk, $pending);
                $pending = [];
            }
        }

        $this->checkReferenced($disk, $pending);
    }

    /**
     * @param  array<string, int>  $files  path => bytes
     */
    private function checkReferenced(string $disk, array $files): void
    {
        if ($files === []) {
            return;
        }

        $paths = array_map('strval', array_keys($files));
        $referenced = [];

        foreach (self::REFERENCES as $reference) {
            if (! isset($reference['disk_column']) && $reference['disk'] !== $disk) {
                continue;
            }

            DB::table($reference['table'])
                ->whereIn($reference['column'], $paths)
                ->when(isset($reference['disk_column']), fn ($query) => $query->where($reference['disk_column'], $disk))
                ->pluck($reference['column'])
                ->each(function (string $path) use (&$referenced): void {
                    $referenced[$path] = true;
                });
        }

        $exportIds = collect($paths)
            ->filter(fn (string $path): bool => str_starts_with($path, 'filament_exports/'))
            ->map(fn (string $path): int => (int) explode('/', $path)[1]);

        $liveExports = $exportIds->isEmpty() ? collect() : DB::table('exports')->whereIn('id', $exportIds->unique()->values())->where('file_disk', $disk)->pluck('id')->flip();

        foreach ($files as $path => $bytes) {
            $path = (string) $path;
            $isExportFile = str_starts_with($path, 'filament_exports/') && $liveExports->has((int) explode('/', $path)[1]);

            if (isset($referenced[$path]) || $isExportFile) {
                continue;
            }

            $area = &$this->area($disk, $this->areaOf($path));
            $area['unreferenced_files']++;
            $area['unreferenced_bytes'] += $bytes;
            unset($area);

            if ($this->option('list')) {
                $this->line("unreferenced {$disk}:{$path}");
            }
        }
    }

    /**
     * @param  array{table: string, column: string, disk: string, disk_column?: string}  $reference
     * @return array{reference: string, records: int, missing: int}
     */
    private function missingFiles(array $reference): array
    {
        $records = 0;
        $missing = 0;
        $columns = array_filter(['id', $reference['column'], $reference['disk_column'] ?? null]);

        DB::table($reference['table'])
            ->whereNotNull($reference['column'])
            ->where($reference['column'], '!=', '')
            ->select($columns)
            ->lazyById(self::BATCH)
            ->each(function (object $row) use ($reference, &$records, &$missing): void {
                $records++;
                $disk = isset($reference['disk_column']) ? (string) $row->{$reference['disk_column']} : $reference['disk'];

                if (! Storage::disk($disk)->exists($row->{$reference['column']})) {
                    $missing++;

                    if ($this->option('list')) {
                        $this->line("missing {$reference['table']}#{$row->id} {$disk}:{$row->{$reference['column']}}");
                    }
                }
            });

        return ['reference' => "{$reference['table']}.{$reference['column']}", 'records' => $records, 'missing' => $missing];
    }

    private function areaOf(string $path): string
    {
        return str_contains($path, '/') ? strstr($path, '/', true) : '(root)';
    }

    /**
     * @return array{disk: string, area: string, files: int, bytes: int, unreferenced_files: int, unreferenced_bytes: int, transient: bool}
     */
    private function &area(string $disk, string $area): array
    {
        $this->areas["{$disk}:{$area}"] ??= [
            'disk' => $disk, 'area' => $area, 'files' => 0, 'bytes' => 0,
            'unreferenced_files' => 0, 'unreferenced_bytes' => 0,
            'transient' => in_array($area, self::TRANSIENT_AREAS, true),
        ];

        return $this->areas["{$disk}:{$area}"];
    }
}
