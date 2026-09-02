<?php

use App\Enums\AppTheme;
use App\Filament\Pages\Profile;
use App\Filament\Pages\ThemeGallery;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a user with no theme preference gets Executive Navy by default', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => null]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee("setAttribute('data-app-theme', 'navy')", false);
});

test('a user\'s persisted theme is applied server-side on every page load, before any JS runs', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'teal']);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee("setAttribute('data-app-theme', 'teal')", false);
});

test('an unknown or stale stored theme value falls back to the default rather than erroring', function (): void {
    expect(AppTheme::fromValueOrDefault('some-retired-theme'))->toBe(AppTheme::default())
        ->and(AppTheme::fromValueOrDefault(null))->toBe(AppTheme::default())
        ->and(AppTheme::fromValueOrDefault('teal'))->toBe(AppTheme::TealSlate);
});

test('the profile page persists a chosen theme to the user record', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'navy']);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(Profile::class)->call('setThemePreference', 'purple');

    expect($user->fresh()->theme)->toBe('purple');
});

test('an invalid theme value submitted to the profile page falls back to the default, not silently stored as-is', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'navy']);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(Profile::class)->call('setThemePreference', 'not-a-real-theme');

    expect($user->fresh()->theme)->toBe(AppTheme::default()->value);
});

test('the theme gallery lists all 8 themes with real descriptions and best-for text, and marks the active one', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'emerald']);
    $user->assignRole('recruiter');

    $html = actingAs($user)->get('/admin/theme-gallery')->assertSuccessful()->getContent();

    foreach (AppTheme::cases() as $theme) {
        expect($html)->toContain(e($theme->label()))
            ->toContain(e($theme->description()))
            ->toContain(e($theme->bestFor()));
    }

    // "Currently Active" is the exact button label used only on the one card matching the user's
    // stored theme (see theme-gallery.blade.php) — a more specific signal than the word "Active"
    // alone, which also appears inside that same button's text.
    expect(substr_count($html, 'Currently Active'))->toBe(1);
});

test('applying a theme from the gallery persists it and updates which card is marked active', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'navy']);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(ThemeGallery::class)
        ->call('applyTheme', 'graphite')
        ->assertNotified();

    expect($user->fresh()->theme)->toBe('graphite');
});

test('previewing themes in the gallery never writes to the database', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id, 'theme' => 'navy']);
    $user->assignRole('recruiter');
    actingAs($user);

    // The gallery's light/dark toggle and per-card swatches are pure Alpine/inline-style state —
    // rendering the page (without calling applyTheme) must never touch the stored preference.
    actingAs($user)->get('/admin/theme-gallery')->assertSuccessful();

    expect($user->fresh()->theme)->toBe('navy');
});

test('the theme gallery is reachable by a plain recruiter, not just admin roles', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('recruiter');

    actingAs($user)->get('/admin/theme-gallery')->assertSuccessful();
});
