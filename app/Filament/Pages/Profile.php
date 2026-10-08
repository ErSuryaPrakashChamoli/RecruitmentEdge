<?php

namespace App\Filament\Pages;

use App\Enums\AppTheme;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Identity\CredentialService;
use App\Services\Identity\TenantSelectionService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantStorage;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Extends Filament's default profile page with:
 * - an "Appearance" section holding the brand-theme picker (previously a topbar icon strip,
 *   moved here per explicit request — a personal display preference belongs on the profile
 *   page, not permanently occupying header space on every screen). Persisted to `users.theme`
 *   via setThemePreference() below, called directly from the picker's Alpine handler
 *   (resources/views/filament/components/theme-picker-section.blade.php) — the same mechanism the
 *   Theme Gallery's "Apply Theme" action uses (App\Filament\Pages\ThemeGallery).
 * - a "Profile Photo" upload, persisted on the linked Employee record (not User) since that's
 *   where employment data lives; also used panel-wide as the avatar via User::getFilamentAvatarUrl().
 * - a read-only "Employee Details" section surfacing HR-managed fields (ID, designation,
 *   department, location, reporting line, category, level, status, role) sourced from the
 *   Employee record. These are display-only here; HR edits them via the Employee resource.
 */
class Profile extends EditProfile
{
    /**
     * SaaS-1/2: the profile is Filament's tenant-less page: the identity's own name, email, password
     * and MFA are global. The employee details and photo belong to a tenant — the one the person
     * last entered in this session, else their default (TenantSelectionService), each re-checked
     * against their membership on every request of this page (Livewire updates included). With no
     * such tenant the page shows the identity only.
     */
    public function boot(): void
    {
        $user = $this->getUser();
        $tenant = $user instanceof User ? app(TenantSelectionService::class)->workingTenant($user, request()->hasSession() ? request()->session() : null) : null;

        if ($tenant instanceof Tenant) {
            TenantContext::current()->setTenant($tenant);
        }
    }

    /**
     * Phase 8.4: sign out of every other browser and device (this session stays signed in).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('signOutOtherSessions')
                ->label('Sign out other sessions')
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Every other browser or device signed in as you is signed out. This one stays signed in.')
                ->action(function (): void {
                    /** @var User $user */
                    $user = $this->getUser();
                    app(CredentialService::class)->signOutEverywhere($user, $user, session()->driver());

                    Notification::make()->title('Other sessions signed out')->success()->send();
                }),
        ];
    }

    /**
     * Phase 8.4: the change is audited when requested; it applies only once the new address is
     * verified (the panel's email change verification).
     */
    protected function sendEmailChangeVerification(Model $record, string $newEmail): void
    {
        if ($record instanceof User && $record->email !== $newEmail) {
            CredentialService::recordEmailChangeRequest($record, $newEmail, $record);

            // SaaS-2 (S1-01): an address another identity uses gets no link, and the page says the
            // same as for any other address — it never reveals who is on the platform.
            if (CredentialService::addressIsTaken($record, $newEmail)) {
                $this->getEmailChangeVerificationSentNotification($newEmail)?->send();
                $this->data['email'] = $record->getAttributeValue('email');

                return;
            }
        }

        parent::sendEmailChangeVerification($record, $newEmail);
    }

    /**
     * SaaS-2 (S1-01): no uniqueness check on the address (Filament's default would answer "already
     * taken" for any address on the platform); sendEmailChangeVerification() handles a taken one.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/edit-profile.form.email.label'))
            ->email()
            ->required()
            ->maxLength(255)
            ->live(debounce: 500);
    }

    public function setThemePreference(string $theme): void
    {
        $this->getUser()->update(['theme' => AppTheme::fromValueOrDefault($theme)->value]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            $this->getAppearanceSectionComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getCurrentPasswordFormComponent(),
                    ]),
                $this->getProfilePhotoSectionComponent(),
                $this->getEmployeeDetailsSectionComponent(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $employee = $this->getUser()->employee;

        $data['photo'] = $employee?->photo_path;

        if ($employee !== null) {
            $data['employee_code_display'] = $employee->employee_code;
            $data['full_name_display'] = $employee->fullName();
            $data['department_display'] = $employee->department?->name;
            $data['designation_display'] = $employee->designation?->name;
            $data['location_display'] = $employee->location?->name;
            $data['reports_to_display'] = $employee->reportsTo?->fullName();
            $data['category_display'] = $employee->category;
            $data['level_display'] = $employee->level;
            $data['status_display'] = $employee->status?->label();
            $data['date_of_joining_display'] = $employee->date_of_joining?->format('d M Y');
            $data['role_display'] = $this->getUser()->roles->pluck('name')->join(', ') ?: '—';
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $photo = Arr::pull($data, 'photo');

        $this->getUser()->employee?->update(['photo_path' => $photo]);

        return $data;
    }

    /**
     * The base EditProfile page saves via AJAX with no redirect, so the topbar avatar (a
     * separate, unrelated Livewire component) never re-renders with the new photo. Redirecting
     * back to this same page forces a fresh page load, so a saved photo shows up immediately
     * everywhere it's used — not just after a manual browser refresh.
     */
    protected function getRedirectUrl(): ?string
    {
        return static::getUrl();
    }

    protected function getAppearanceSectionComponent(): Component
    {
        return Section::make('Appearance')
            ->description('Choose the brand color theme for the whole panel. Applies immediately and follows your account — logging in elsewhere keeps the same theme. See Administration → Theme Gallery to compare all 8 before choosing.')
            ->schema([
                View::make('filament.components.theme-picker-section'),
            ]);
    }

    protected function getProfilePhotoSectionComponent(): Component
    {
        return Section::make('Profile Photo')
            ->description('Shown as your avatar throughout the panel.')
            ->schema([
                FileUpload::make('photo')
                    ->label('Photo')
                    ->image()
                    ->avatar()
                    // Phase 8.10 (P810-SEC-002): raster only (after avatar(), which resets the types to image/*).
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    // SaaS-5 (S1-06): private; a photo not yet moved stays readable where it is.
                    ->disk(fn (): string => Employee::photoDisk($this->getUser()->employee?->photo_path))
                    ->visibility('private')
                    ->directory(fn (): string => TenantStorage::path('employee-photos')),
            ])
            ->visible(fn (): bool => $this->getUser()->employee !== null);
    }

    protected function getEmployeeDetailsSectionComponent(): Component
    {
        $employee = $this->getUser()->employee;

        return Section::make('Employee Details')
            ->description('Managed by HR. Contact your administrator to request changes.')
            ->columns(2)
            ->schema([
                TextInput::make('employee_code_display')
                    ->label('Employee ID')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('full_name_display')
                    ->label('Full Name')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('department_display')
                    ->label('Department')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('designation_display')
                    ->label('Designation')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('location_display')
                    ->label('Location')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('reports_to_display')
                    ->label('Reports To')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('category_display')
                    ->label('Category')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('level_display')
                    ->label('Level')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('status_display')
                    ->label('Status')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('date_of_joining_display')
                    ->label('Date of Joining')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('role_display')
                    ->label('Role')
                    ->disabled()
                    ->dehydrated(false),
            ])
            ->visible(fn (): bool => $employee !== null);
    }
}
