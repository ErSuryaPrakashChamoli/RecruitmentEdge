<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('demo:setup {--force : Skip the confirmation prompt}')]
#[Description('Wipe this database and fill it with the Recruitment Edge demo (demo installations only)')]
class SetupDemo extends Command
{
    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->error('Demo mode is off for this installation. Set APP_DEMO=true in the demo installation\'s .env first — never on a real installation: this command deletes ALL data.');

            return self::FAILURE;
        }

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! $this->option('force') && ! $this->confirm("This deletes ALL data in the [{$database}] database and replaces it with the demo. Continue?")) {
            $this->warn('Demo setup cancelled.');

            return self::FAILURE;
        }

        $this->components->task('Rebuilding the database', fn () => $this->callSilently('migrate:fresh', ['--force' => true]) === self::SUCCESS);
        $this->callSilently('cache:clear');
        Storage::disk('local')->deleteDirectory('ai-documents');

        $this->components->info('Seeding the demo story (this takes a minute or two)...');
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        // Index the AI knowledge base now rather than waiting for a queue worker. Needs an AI
        // provider key; without one the Copilot shows its "not configured" state.
        try {
            $this->callSilently('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);
        } catch (Throwable $e) {
            $this->warn('AI knowledge indexing did not finish: '.$e->getMessage());
        }

        $this->newLine();
        $this->components->info('The demo is ready. Demo accounts (password: '.config('demo.password').'):');
        $this->table(['Role', 'Email'], collect(DemoCatalog::LOGINS)
            ->map(fn (array $login): array => [$login['role'], DemoCatalog::personEmail($login['person'])])
            ->all());

        return self::SUCCESS;
    }
}
