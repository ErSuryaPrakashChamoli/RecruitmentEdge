<?php

namespace App\Filament\Pages;

use App\Enums\AppTheme;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Browse and compare all 8 brand themes side by side before choosing one — the same underlying
 * preference (`users.theme`) the Profile page's quick picker writes to, via the same
 * setThemePreference() persistence pattern. Deliberately open to every authenticated user (not
 * gated behind an admin permission): a personal display preference, just presented under
 * Administration for discoverability per the brief, not an administrative action.
 *
 * "Preview" never touches the real active theme — each card is a self-contained mock UI snippet
 * with that theme's shades set as inline CSS custom properties on its own wrapper, not on
 * <html data-app-theme>, so nothing here can leak into the rest of the panel until "Apply Theme"
 * is clicked.
 */
class ThemeGallery extends Page
{
    protected string $view = 'filament.pages.theme-gallery';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Theme Gallery';

    public static function canAccess(): bool
    {
        return Filament::auth()->check();
    }

    /**
     * @return array<int, AppTheme>
     */
    public function getThemes(): array
    {
        return AppTheme::cases();
    }

    public function getCurrentTheme(): AppTheme
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return AppTheme::fromValueOrDefault($user->theme);
    }

    public function applyTheme(string $theme): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $theme = AppTheme::fromValueOrDefault($theme);

        $user->update(['theme' => $theme->value]);

        Notification::make()
            ->title("{$theme->label()} applied")
            ->success()
            ->send();
    }
}
