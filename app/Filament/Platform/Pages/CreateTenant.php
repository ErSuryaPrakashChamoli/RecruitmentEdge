<?php

namespace App\Filament\Platform\Pages;

use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\PlatformDirectory;
use App\Services\Platform\TenantCommercialService;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Platform commercial UI: provisioning a tenant from the panel — the same TenantProvisioningService
 * as tenants:provision, through TenantCommercialService. The wizard only gathers and checks the
 * shape of the input; the service decides (slug rules, plan, owner invitation, trial, idempotency).
 * Region fields left empty take the provisioning request's own defaults.
 */
class CreateTenant extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithPlatform;

    protected static ?string $slug = 'create-tenant';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Tenants';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Create tenant';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::TenantsManage);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form')]);
    }

    public function form(Schema $schema): Schema
    {
        $offered = app(PlatformDirectory::class)->offeredPlans();

        return $schema
            ->components([
                Wizard::make([
                    Step::make('Company')
                        ->description('The organisation and its address in the platform')
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')->label('Company name')->required()->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null),
                            TextInput::make('legal_name')->label('Legal name')->maxLength(255),
                            TextInput::make('slug')->label('Tenant slug')->required()->maxLength(63)
                                ->helperText('Lower-case letters, digits and hyphens. It becomes part of the tenant\'s addresses (/admin/{slug}, /careers/{slug}) and cannot be changed later.'),
                            Section::make('Region')
                                ->description('Leave a field empty to use the provisioning default. The tenant detail shows the stored values afterwards.')
                                ->columnSpanFull()
                                ->columns(4)
                                ->schema([
                                    TextInput::make('country')->label('Country (ISO code)')->placeholder('Provisioning default')->length(2)->regex('/^[A-Za-z]{2}$/')
                                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null),
                                    Select::make('timezone')->placeholder('Provisioning default')->searchable()
                                        ->options(fn (): array => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())),
                                    TextInput::make('currency')->label('Currency (ISO code)')->placeholder('Provisioning default')->length(3)->regex('/^[A-Za-z]{3}$/')
                                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null),
                                    TextInput::make('locale')->placeholder('Provisioning default')->maxLength(16)->regex('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/'),
                                ]),
                        ]),
                    Step::make('Owner')
                        ->description('Invited as the tenant\'s owner and CHRO')
                        ->columns(2)
                        ->schema([
                            TextInput::make('owner_name')->label('Owner name')->required()->maxLength(255),
                            TextInput::make('owner_email')->label('Owner email')->email()->required()->maxLength(255)
                                ->helperText('They receive an invitation and set their own password. An existing login is invited, never duplicated.'),
                        ]),
                    Step::make('Plan')
                        ->description('Plans offered to new tenants, at their latest published version')
                        ->schema([
                            Select::make('plan')->required()->live()
                                ->options(collect($offered)->map(fn (array $plan): string => $plan['label'])->all())
                                ->in(array_keys($offered)),
                            KeyValueEntry::make('plan_grants')->label('What the plan includes')->keyLabel('Entitlement')->valueLabel('Plan value')
                                ->visible(fn (Get $get): bool => filled($get('plan')))
                                ->state(fn (Get $get): array => $offered[$get('plan')]['grants'] ?? []),
                        ]),
                    Step::make('Trial')
                        ->description('Start with a trial, or activate straight away')
                        ->columns(2)
                        ->schema([
                            Toggle::make('trial')->label('Start with a trial')->live(),
                            TextInput::make('trial_days')->label('Trial days')->numeric()->integer()->minValue(1)
                                ->required(fn (Get $get): bool => (bool) $get('trial'))
                                ->visible(fn (Get $get): bool => (bool) $get('trial')),
                        ]),
                    Step::make('Review')
                        ->description('Nothing is created until you confirm')
                        ->schema([
                            KeyValueEntry::make('review')->hiddenLabel()->keyLabel('Field')->valueLabel('Value')
                                ->state(fn (Get $get): array => self::review($get, $offered)),
                        ]),
                ])
                    ->submitAction(Action::make('create')->label('Create tenant')->icon('heroicon-o-check')->action('create'))
                    ->alpineSubmitHandler('$wire.create()')
                    ->contained(false),
            ])
            ->statePath('data');
    }

    public function create(): void
    {
        $data = $this->form->getState();
        $slug = trim((string) $data['slug']);
        // Whether the slug was already a provisioned tenant: the service then returns it unchanged
        // (a repeated request, or a tenant from before provisioning), and nothing new is created.
        $alreadyProvisioned = Tenant::query()->where('slug', $slug)->whereNotNull('provisioned_at')->exists();

        try {
            /** @var Tenant $tenant */
            $tenant = self::perform(fn (User $operator): Tenant => app(TenantCommercialService::class)->provision([
                'slug' => $slug,
                'name' => (string) $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'owner_name' => $data['owner_name'] ?? null,
                'owner_email' => (string) $data['owner_email'],
                'plan' => (string) $data['plan'],
                'trial_days' => ($data['trial'] ?? false) ? (int) $data['trial_days'] : null,
                'country' => $data['country'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'currency' => $data['currency'] ?? null,
                'locale' => $data['locale'] ?? null,
            ], $operator), fn (Tenant $tenant): Notification => $alreadyProvisioned
                ? Notification::make()->title('Nothing new was created')->body("The slug \"{$tenant->slug}\" already belongs to {$tenant->name}; it is unchanged.")->warning()->persistent()
                : Notification::make()->title('Tenant created — the owner has been invited')->success());
        } catch (Halt) {
            // Refused by the service: the reason is already on screen, the form keeps its input.
            return;
        }

        $this->redirect(TenantDetail::getUrl(['tenant' => $tenant->getKey()]));
    }

    /**
     * @param  array<string, array{label: string, grants: array<string, string>}>  $offered
     * @return array<string, string>
     */
    private static function review(Get $get, array $offered): array
    {
        $default = fn (?string $value): string => filled($value) ? (string) $value : 'Provisioning default';

        return [
            'Company' => (string) $get('name'),
            'Legal name' => filled($get('legal_name')) ? (string) $get('legal_name') : '—',
            'Slug' => (string) $get('slug'),
            'Owner' => trim($get('owner_name').' <'.$get('owner_email').'>'),
            'Plan' => $offered[$get('plan')]['label'] ?? '—',
            'Trial' => $get('trial') ? ((int) $get('trial_days')).' days' : 'None — active straight away',
            'Country' => $default($get('country')),
            'Timezone' => $default($get('timezone')),
            'Currency' => $default($get('currency')),
            'Locale' => $default($get('locale')),
        ];
    }
}
