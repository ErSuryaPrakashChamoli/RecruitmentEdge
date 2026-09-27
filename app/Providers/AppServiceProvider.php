<?php

namespace App\Providers;

use App\Logging\RedactingFailedJobProvider;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Rules\NotCommonPassword;
use App\Services\Automation\AutomationActionRegistry;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Automation\AutomationFieldRegistry;
use App\Services\Automation\AutomationRuntime;
use App\Services\Communication\CommunicationProviderManager;
use App\Services\Distribution\JobBoardRegistry;
use App\Services\Identity\StaffAccessService;
use App\Services\Integrations\Calendar\CalendarManager;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\Video\ZoomMeetingProvider;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->app->singleton(AutomationEventRegistry::class);
        $this->app->singleton(AutomationFieldRegistry::class);
        $this->app->singleton(AutomationActionRegistry::class);
        // Phase 8.4: one access gate per process, so its per-user decisions are memoised for the
        // request (every identity change invalidates them — StaffAccessService::invalidateDecisions).
        $this->app->singleton(StaffAccessService::class);

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

        $this->configureTables();
        $this->configurePortalRateLimits();
        $this->configurePasswordPolicy();
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
}
