<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Database\Seeders\AiEvaluationSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RecruitmentReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the complete Recruitment Edge demo: a fictitious company with six months of recruitment
 * history, played through the product's own services (see DemoRecruitmentStory), plus the AI
 * Copilot workspace. Run it through `php artisan demo:setup` on a demo installation only.
 *
 * Like DatabaseSeeder, deliberately does NOT use WithoutModelEvents: the employee hierarchy,
 * duplicate detection, joining creation and audit logs all rely on model events.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            OrganizationSeeder::class,
            RecruitmentReferenceDataSeeder::class,
            AiEvaluationSeeder::class,
        ]);

        $context = new DemoContext((float) config('demo.scale', 1), (int) config('demo.random_seed'));

        // Filament's database notifications are queued: deliver them while seeding, so each one is
        // stamped with its moment in the story rather than whenever a queue worker gets to it.
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            DB::transaction(function () use ($context): void {
                (new DemoOrganization($context))->build();
                DemoRecruitmentStory::for($context)->play();
                (new DemoAiWorkspace($context))->build();
            });

            Auth::guard('web')->forgetUser();

            // The nightly jobs a live installation would have run by now.
            foreach (['offers:expire-lapsed', 'incentives:release-matured', 'performance:snapshot', 'notifications:dispatch-alerts'] as $command) {
                Artisan::call($command);
            }

            $this->leaveRecentNotificationsUnread($context);
        } finally {
            config(['queue.default' => $queue]);
        }

        // Embedding the knowledge base calls the AI provider, so it goes on the regular queue
        // (demo:setup works it off) instead of running inside the seed.
        Artisan::call('ai:reindex-knowledge');

        $this->command?->info('Demo data seeded. Sign in with any demo account — password: '.config('demo.password'));
    }

    /**
     * As on a lived-in account, only each user's latest handful of notifications is still unread.
     */
    private function leaveRecentNotificationsUnread(DemoContext $context): void
    {
        $notifiableType = (new User)->getMorphClass();

        foreach (User::query()->pluck('id') as $userId) {
            $unread = DB::table('notifications')->where('notifiable_type', $notifiableType)->where('notifiable_id', $userId)->whereNull('read_at');
            $keep = (clone $unread)->where('created_at', '>=', $context->today->subDays(2))->latest('created_at')->limit(8)->pluck('id');

            $unread->whereNotIn('id', $keep)->update(['read_at' => DB::raw('created_at')]);
        }
    }
}
