<?php

namespace App\Providers\Filament;

use App\Filament\Auth\StaffAppAuthentication;
use App\Filament\Pages\Auth\StaffLogin;
use App\Filament\Pages\Auth\StaffRequestPasswordReset;
use App\Filament\Platform\Pages\Dashboard;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnforceStaffAccess;
use App\Http\Middleware\EnsurePlatformMfa;
use App\Http\Middleware\UseCandidateSessionContext;
use App\Services\Branding;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * SaaS-5: the platform control plane — a separate panel at /platform, without tenancy. Only
 * platform operators get in (User::canAccessPanel: an active platform role), every one of them
 * with MFA; what each page and action allows comes from PlatformCapability, checked again by
 * every platform service. It shows operational metadata across tenants (PlatformDirectory) and a
 * tenant's data only through that tenant's own support grant — never by entering the tenant.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(fn (): string => Branding::platformName().' · Platform')
            ->favicon(asset('favicon.svg'))
            ->font('Instrument Sans')
            ->defaultThemeMode(ThemeMode::Light)
            ->login(StaffLogin::class)
            ->passwordReset(StaffRequestPasswordReset::class)
            ->multiFactorAuthentication([StaffAppAuthentication::make()->recoverable()->regenerableRecoveryCodes()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsurePlatformMfa::class)
            ->colors([
                'primary' => Color::hex('#7A1F2B'),
                'gray' => Color::Zinc,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Blue,
            ])
            ->navigationGroups(['Tenants', 'Support', 'Compliance', 'Operations'])
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\Filament\Platform\Widgets')
            ->middleware([
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
                AddSecurityHeaders::class,
            ])
            // Persistent: every Livewire update re-checks the session and the operator's roles — a
            // revoked operator or a session from before a revocation is signed out on the spot.
            ->authMiddleware([
                EnforceStaffAccess::class,
                Authenticate::class,
            ], isPersistent: true);
    }
}
