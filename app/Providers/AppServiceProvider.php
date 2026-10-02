<?php

namespace App\Providers;

use App\Http\Controllers\PrivateFileController;
use App\Http\Middleware\AuditExportDownload;
use App\Http\Middleware\EnforceStaffAccess;
use App\Http\Middleware\EnsureCandidateSessionIsCurrent;
use App\Http\Middleware\EnsureStaffMfa;
use App\Http\Middleware\UseCandidateSessionContext;
use App\Http\Session\StaffDatabaseSessionHandler;
use App\Logging\RedactingFailedJobProvider;
use App\Models\AuditLog;
use App\Models\CandidatePortalAccount;
use App\Models\Role;
use App\Models\User;
use App\Policies\ExportPolicy;
use App\Policies\RolePolicy;
use App\Rules\NotCommonPassword;
use App\Services\Automation\AutomationActionRegistry;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Automation\AutomationFieldRegistry;
use App\Services\Automation\AutomationRuntime;
use App\Services\CandidatePortalService;
use App\Services\Communication\CommunicationProviderManager;
use App\Services\Distribution\JobBoardRegistry;
use App\Services\Export\ExportGovernance;
use App\Services\HierarchyMemo;
use App\Services\Identity\StaffAccessService;
use App\Services\Integrations\Calendar\CalendarManager;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\Video\ZoomMeetingProvider;
use App\Services\SchedulerHeartbeat;
use App\Services\WorkerHeartbeat;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 5: every external integration, for honest implemented/configured/operational
        // reporting (Administration → Integrations). Adapters themselves are resolved lazily.
        // Phase 6 automation: the runtime must be one instance per process (loop prevention); the
        // registries are stateless catalogues built once.
        $this->app->singleton(AutomationRuntime::class);

        // Phase 8.7 (D8.7-017, SEC-87-02): Filament's queued auth mails carry bearer-token URLs —
        // resolve encrypted subclasses (Filament builds them through the container).
        $this->app->bind(ResetPassword::class, \App\Notifications\Auth\ResetPassword::class);
        $this->app->bind(NoticeOfEmailChangeRequest::class, \App\Notifications\Auth\NoticeOfEmailChangeRequest::class);
        // Phase 8.9 (P89-SEC-002): the email-change verification link is encrypted in the queue too.
        $this->app->bind(VerifyEmailChange::class, \App\Notifications\Auth\VerifyEmailChange::class);
        $this->app->singleton(AutomationEventRegistry::class);
        $this->app->singleton(AutomationFieldRegistry::class);
        $this->app->singleton(AutomationActionRegistry::class);
        // Phase 8.4: one access gate per process, so its per-user decisions are memoised for the
        // request (every identity change invalidates them — StaffAccessService::invalidateDecisions).
        $this->app->singleton(StaffAccessService::class);
        // Phase 8.9 (P89-PERF-012): one subtree memo per request / queued job.
        $this->app->scoped(HierarchyMemo::class);

        $this->app->singleton(IntegrationRegistry::class, function (): IntegrationRegistry {
            $registry = new IntegrationRegistry;

            foreach ([...CommunicationProviderManager::PROVIDERS, ...CalendarManager::PROVIDERS, 'zoom' => ZoomMeetingProvider::class, ...JobBoardRegistry::CONNECTORS] as $key => $class) {
                $registry->register($key, $class);
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 8.4: App\Models\Role (the configured Spatie role model) — registered explicitly so
        // the policy never depends on auto-discovery for this security-critical model.
        Gate::policy(Role::class, RolePolicy::class);

        // Phase 8.4: a suspended or revoked login may do nothing, whatever a policy would allow.
        Gate::before(fn (mixed $user): ?bool => $user instanceof User && ! app(StaffAccessService::class)->permits($user) ? false : null);

        // Phase 8.6 (D8.6-027): fail closed. Outside strict mode Filament treats a policy that lacks
        // the ability's method as "allowed" unless a before-callback denies it; this is that denial.
        // It only applies when the model has a policy, the policy has no such method and no gate
        // ability of that name is defined — every rule must be written down, never assumed.
        Gate::before(fn (mixed $user, string $ability, array $arguments = []): ?bool => self::policyLacksAbility($ability, $arguments) ? false : null);

        // Phase 8.7 (SEC-87-07): exception text stored in failed_jobs is redacted centrally. The
        // queue provider is deferred (and would re-bind over an extender), so the store is wrapped
        // here, once; constructing it runs no query.
        $failer = $this->app->make('queue.failer');
        $this->app->instance('queue.failer', $failer instanceof RedactingFailedJobProvider ? $failer : new RedactingFailedJobProvider($failer));

        $this->configureAsyncContext();
        $this->configureSchedulerHeartbeat();
        $this->configureHealthCheck();
        $this->configureCandidateSessions();

        $this->configureTables();
        $this->configurePortalRateLimits();
        $this->configurePasswordPolicy();

        $this->configureUploads();
        $this->configureExports();
        $this->configurePrivateFiles();
    }

    /**
     * Phase 8.8 (SEC-88-06): every upload field accepts only a file uploaded in that form or the
     * value already stored — a submitted path can never point the record at another stored file.
     */
    private function configureUploads(): void
    {
        FileUpload::configureUsing(fn (FileUpload $upload): FileUpload => $upload->preventFilePathTampering());
    }

    /**
     * Phase 8.8 (SEC-88-03, SEC-88-12, SEC-88-13, SEC-88-24): every Filament table export is capped,
     * written without live spreadsheet formulas and audited; its file is downloadable only by its
     * owner within the download window, behind the panel's staff-access and MFA checks.
     */
    private function configureExports(): void
    {
        ExportAction::configureUsing(fn (ExportAction $action): ExportAction => $action
            ->maxRows(ExportGovernance::MAX_ROWS)
            ->before(fn (ExportAction $action, array $data, Component $livewire) => ExportGovernance::rememberRequest($action, $data, $livewire))
            ->after(fn () => ExportGovernance::recordRefusedIfNotStarted()));

        ExportColumn::configureUsing(fn (ExportColumn $column): ExportColumn => $column->preventFormulaInjection());

        Export::created(fn (Export $export) => ExportGovernance::recordRequested($export));

        Gate::policy(Export::class, ExportPolicy::class);

        // The download route is Filament's (not a panel route); its middleware group gets the
        // panel's staff-access and MFA checks, then the download audit.
        $this->app['router']->middlewareGroup('filament.actions', ['web', EnforceStaffAccess::class, EnsureStaffMfa::class, AuditExportDownload::class]);
    }

    /**
     * Phase 8.8 (SEC-88-17): the private disk is not served at storage/{path}; its temporary URLs
     * (Filament file previews) point at the authenticated, audited files.private route instead.
     */
    private function configurePrivateFiles(): void
    {
        PrivateFileController::registerTemporaryUrls();
    }

    /**
     * Phase 8.4: the staff password policy — Filament's profile and reset pages and the Users form
     * all use Password::defaults().
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min((int) config('identity.password.min_length', 12))
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->rules([new NotCommonPassword]);

            return config('identity.password.check_breached') ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Candidate portal throttles (Phase 4): sign-in/password endpoints per IP, emailed links per
     * IP + email, and everyday portal/self-scheduling actions per candidate (or IP when signed-link
     * only). The login controller additionally locks out an email + IP after repeated failures.
     */
    private function configurePortalRateLimits(): void
    {
        RateLimiter::for('portal-auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('portal-links', fn (Request $request) => Limit::perMinute(3)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        RateLimiter::for('career-apply', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        RateLimiter::for('portal-actions', fn (Request $request) => Limit::perMinute(60)->by($request->user('candidate')?->getAuthIdentifier() ?? $request->ip()));
        // Phase 8.9 (P89-SEC-010): per signed-in staff user — the private-file links and the calendar
        // OAuth flow need a valid session and signature, so these bound load, not access.
        RateLimiter::for('private-files', fn (Request $request) => Limit::perMinute(300)->by('staff:'.($request->user('web')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('calendar-oauth', fn (Request $request) => Limit::perMinute(20)->by('staff:'.($request->user('web')?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Project-wide table defaults. Record actions render in the first column, where the admin theme
     * (resources/css/filament/admin/theme.css) shows them in a strip as the hovered row expands. Every
     * row (list pages, relation managers, widgets) opens its record's view page, falling back to the
     * edit page — Filament's own list-page default would pick an EditAction's URL first whenever the
     * table has no ViewAction. Records without a resource page keep Filament's modal record action.
     * Any table that sets its own position or recordUrl() still overrides these defaults.
     */
    private function configureTables(): void
    {
        Table::configureUsing(fn (Table $table): Table => $table
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->recordUrl(fn (mixed $record): ?string => self::resourceRecordUrl($record)));
    }

    private static function resourceRecordUrl(mixed $record): ?string
    {
        if (! $record instanceof Model) {
            return null;
        }

        $resource = Filament::getModelResource($record);

        if ($resource === null) {
            return null;
        }

        foreach (['view', 'edit'] as $page) {
            if ($resource::hasPage($page) && $resource::{'can'.ucfirst($page)}($record)) {
                return $resource::getUrl($page, ['record' => $record]);
            }
        }

        return null;
    }

    /**
     * Whether the first argument's policy exists but defines no method for the ability.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function policyLacksAbility(string $ability, array $arguments): bool
    {
        $target = $arguments[0] ?? null;

        if (! ($target instanceof Model) && ! (is_string($target) && is_subclass_of($target, Model::class))) {
            return false;
        }

        $policy = Gate::getPolicyFor($target);

        return $policy !== null && ! method_exists($policy, $ability) && ! Gate::has($ability);
    }

    /**
     * Phase 8.7 (D8.7-014/015): every unit of work carries a correlation id and an actor kind.
     *
     * - An artisan command without one gets `cmd:<uuid>`; jobs it dispatches carry it (Laravel
     *   Context travels in the payload). A job that arrives without one gets `job:<uuid>`.
     * - Audit rows written by a job record `queue`, by a scheduled command `scheduler` (a scheduled
     *   command name run without a terminal), otherwise `console` — unless the work runs inside
     *   AuditLog::asActor() (automation, AI).
     */
    /**
     * @var array<string, int> job uuid => hrtime when it started (this worker)
     */
    private static array $jobStartedAt = [];

    private function configureAsyncContext(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! Context::has('request_id')) {
                Context::add('request_id', 'cmd:'.Str::uuid());
            }

            if ($event->command !== null && ! str_starts_with($event->command, 'queue:')) {
                AuditLog::setDefaultActorKind(self::isScheduledRun($event->command) ? 'scheduler' : 'console');
            }
        });

        Queue::before(function (JobProcessing $event): void {
            if (! Context::has('request_id')) {
                Context::add('request_id', 'job:'.($event->job->uuid() ?? Str::uuid()));
            }

            AuditLog::setDefaultActorKind('queue');

            // Phase 8.9 (P89-SEC-003, ED-11): a long-lived worker re-reads role permissions for every
            // job — Spatie keeps the role → permission map in memory for the process, so a role
            // edited in the panel would otherwise be honoured with its old permissions for up to an
            // hour (--max-time). The cached map (flushed by Spatie on every role/permission change)
            // is read again on the job's first permission check.
            app(PermissionRegistrar::class)->clearPermissionsCollection();

            // Phase 8.9 (P89-OPS-006): which job did this — on every log line of the job (Context is
            // added to the log's extra), and in the processed line below.
            Context::add('job', ['uuid' => $event->job->uuid(), 'name' => $event->job->resolveName(), 'queue' => $event->job->getQueue(), 'attempt' => $event->job->attempts()]);
            self::$jobStartedAt[(string) $event->job->uuid()] = hrtime(true);
        });

        Queue::after(function (JobProcessed $event): void {
            AuditLog::setDefaultActorKind(null);

            // Phase 8.9 (P89-OPS-006): a successful job leaves a trace — class, queue, attempt and
            // duration — correlated by request id with what it changed (audit rows carry the same id).
            $started = self::$jobStartedAt[(string) $event->job->uuid()] ?? null;
            unset(self::$jobStartedAt[(string) $event->job->uuid()]);
            Log::info('queue.job_processed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
                'duration_ms' => $started !== null ? (int) round((hrtime(true) - $started) / 1e6) : null,
            ]);
        });
        Queue::failing(fn () => AuditLog::setDefaultActorKind(null));

        // Phase 8.9 (P89-OPS-002/007): a looping worker records its heartbeat (at most once a minute).
        Event::listen(Looping::class, fn (Looping $event) => app(WorkerHeartbeat::class)->beat($event->queue));
    }

    /**
     * Phase 8.9 (P89-OPS-006): GET /up checks the database — sessions, cache and queues all live there,
     * so a reachable web server with an unreachable database is not healthy.
     */
    private function configureHealthCheck(): void
    {
        Event::listen(DiagnosingHealth::class, fn () => DB::connection()->select('select 1'));
    }

    /**
     * Phase 8.7 (D8.7-021/028): every scheduled task's outcome is the scheduler's heartbeat.
     */
    private function configureSchedulerHeartbeat(): void
    {
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => app(SchedulerHeartbeat::class)->record($event->task, $event->task->exitCode === 0 || $event->task->runInBackground ? 'finished' : 'failed'));
        Event::listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => app(SchedulerHeartbeat::class)->record($event->task, $event->task->exitCode === 0 ? 'finished' : 'failed'));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => app(SchedulerHeartbeat::class)->record($event->task, 'failed'));
        Event::listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => app(SchedulerHeartbeat::class)->record($event->task, 'skipped'));
    }

    /**
     * Phase 8.8 (D8.8-001): every candidate sign-in — password, set-password link or remember-me
     * cookie — stores the fingerprint that EnsureCandidateSessionIsCurrent checks.
     */
    private function configureCandidateSessions(): void
    {
        // Phase 8.9 (P89-SEC-007): candidate sessions never record a user id in the shared table.
        Session::extend('database', fn (Application $app) => new StaffDatabaseSessionHandler(
            $app['db']->connection(config('session.connection')),
            (string) config('session.table'),
            (int) config('session.lifetime'),
            $app,
        ));

        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === UseCandidateSessionContext::CANDIDATE_GUARD && $event->user instanceof CandidatePortalAccount && request()->hasSession()) {
                request()->session()->put(EnsureCandidateSessionIsCurrent::SESSION_KEY, app(CandidatePortalService::class)->sessionFingerprint($event->user));
            }
        });
    }

    private static function isScheduledRun(string $command): bool
    {
        if (defined('STDIN') && @stream_isatty(STDIN)) {
            return false;
        }

        return collect(app(Schedule::class)->events())
            ->contains(fn ($event) => is_string($event->command) && preg_match('/artisan[\'"]?\s+'.preg_quote($command, '/').'(\s|$)/', $event->command) === 1);
    }
}
