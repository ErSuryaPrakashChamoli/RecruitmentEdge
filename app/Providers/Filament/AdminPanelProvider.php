<?php

namespace App\Providers\Filament;

use App\Filament\Auth\StaffAppAuthentication;
use App\Filament\Pages\Auth\ChooseTenant;
use App\Filament\Pages\Auth\StaffLogin;
use App\Filament\Pages\Auth\StaffRequestPasswordReset;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Profile;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnforceStaffAccess;
use App\Http\Middleware\EnsureStaffMfa;
use App\Http\Middleware\SetTenantContextFromPanel;
use App\Http\Middleware\UseCandidateSessionContext;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(config('app.name'))
            ->brandLogo(fn () => view('filament.branding.logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->font('Instrument Sans')
            ->defaultThemeMode(ThemeMode::Light)
            ->sidebarCollapsibleOnDesktop()
            // SaaS-1: Filament's native tenancy — every panel page lives under /admin/{tenant slug}.
            // IdentifyTenant checks User::canAccessTenant() (membership, usable tenant, a role);
            // SetTenantContextFromPanel (persistent, so Livewire updates too) turns the selection
            // into the TenantContext that models, roles, jobs and caches enforce on their own.
            ->tenant(Tenant::class, slugAttribute: 'slug', ownershipRelationship: 'tenant')
            ->tenantMiddleware([SetTenantContextFromPanel::class], isPersistent: true)
            // SaaS-2: the switcher lists only tenants the person may enter (User::getTenants); the
            // chooser also sets the default. Tenant-less, but signed in (and MFA-checked).
            ->tenantMenuItems([
                Action::make('chooseOrganisation')
                    ->label('All organisations')
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (): string => ChooseTenant::getUrl())
                    ->visible(fn (): bool => (Filament::auth()->user()?->accessibleTenants()->count() ?? 0) > 1),
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('/organisations', ChooseTenant::class)->middleware(EnsureStaffMfa::class)->name('choose-tenant');
            })
            // Phase 8.4: per-account lockout on top of Filament's per-IP throttle; staff password
            // reset (single-use, expiring broker tokens); email changes apply only once verified.
            // Phase 8.6 (D8.6-027): a Filament action without an explicit policy method throws in
            // local and test runs, so a missing rule is caught before release. In production a
            // missing method is denied by AppServiceProvider's Gate::before (fail closed, no error).
            ->strictAuthorization(fn (): bool => app()->environment(['local', 'testing']))
            ->login(StaffLogin::class)
            // SaaS-2: the request page answers the same for every address (no enumeration).
            ->passwordReset(StaffRequestPasswordReset::class)
            ->emailChangeVerification()
            // Phase 8.4: authenticator-app MFA with recovery codes, required per person (roles and
            // permissions in config/identity.php) by EnsureStaffMfa.
            ->multiFactorAuthentication([StaffAppAuthentication::make()->recoverable()->regenerableRecoveryCodes()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfa::class)
            ->profile(Profile::class, isSimple: false)
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('<livewire:command-palette />'),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): string => view('filament.components.theme-anti-fouc')->render(),
            )
            ->colors([
                'primary' => Color::hex('#1B3B6F'),
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Blue,
            ])
            ->navigationGroups([
                'Overview',
                'Recruitment',
                'Candidate Experience',
                'Communication',
                'Distribution',
                'Automation',
                'EDGE Intelligence',
                'Performance',
                'Incentives',
                'Reports',
                'AI Assistant',
                'Administration',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                // Phase 8.8 (D8.8-001): the panel always uses the staff session cookie and guard.
                UseCandidateSessionContext::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Phase 8.8 (SEC-88-11): the sign-in and password-reset pages get defensive headers.
                AddSecurityHeaders::class,
            ])
            // Phase 8.4: persistent, so Livewire updates are re-checked too — a suspended or revoked
            // login, or a session from before a revocation, is signed out on its next request.
            ->authMiddleware([
                EnforceStaffAccess::class,
                Authenticate::class,
            ], isPersistent: true);
    }
}
