<?php

namespace App\Filament\Platform\Pages;

use App\Enums\PlatformCapability;
use App\Enums\SupportScope;
use App\Enums\TenantStatus;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\ComplianceExportService;
use App\Services\Platform\PlatformDirectory;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\TenantAdministrationService;
use App\Services\Platform\TenantDeletionService;
use App\Services\Platform\TenantOwnershipService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * SaaS-5: one tenant's operational view and the platform's actions on it — lifecycle, ownership,
 * support access requests, compliance exports, deletion. Each action is shown by capability and
 * decided again by its service (authorisation, state, locks, audit).
 */
class TenantDetail extends Page
{
    use InteractsWithPlatform;

    protected static ?string $slug = 'tenants/{tenant}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public int $tenantId;

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::TenantsView);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'tenant-detail';
    }

    public function mount(int|string $tenant): void
    {
        $this->tenantId = (int) Tenant::query()->whereKey((int) $tenant)->valueOrFail('id');
    }

    /**
     * After an action: the next read sees the tenant as it is now.
     */
    public function forgetTenant(): void
    {
        unset($this->tenant, $this->summary);
    }

    #[Computed]
    public function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        return app(PlatformDirectory::class)->summary($this->tenant);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->tenant->name;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tenant')
                ->columns(3)
                ->schema([
                    TextEntry::make('slug')->state(fn (): string => $this->tenant->slug),
                    TextEntry::make('status')->badge()
                        ->state(fn (): string => $this->tenant->effectiveStatus()->label())
                        ->color(fn (): string => $this->tenant->effectiveStatus()->color()),
                    TextEntry::make('status_reason')->label('Status reason')->state(fn (): ?string => $this->tenant->status_reason)->placeholder('—'),
                    TextEntry::make('plan')->state(fn (): ?string => $this->summary['plan'])->placeholder('None'),
                    TextEntry::make('owner')->state(fn (): ?string => $this->summary['owner'] instanceof User ? "{$this->summary['owner']->name} ({$this->summary['owner']->email})" : null)->placeholder('No owner recorded'),
                    TextEntry::make('members')->label('Active members / pending invitations')->state(fn (): string => "{$this->summary['active_members']} / {$this->summary['invited_members']}"),
                    TextEntry::make('trial_ends_at')->label('Trial ends')->state(fn (): ?string => $this->tenant->trial_ends_at?->toDayDateTimeString())->placeholder('—'),
                    TextEntry::make('access_ends_at')->label('Access ends')->state(fn (): ?string => $this->tenant->access_ends_at?->toDayDateTimeString())->placeholder('—'),
                    TextEntry::make('failed_jobs')->label('Failed background jobs')->state(fn (): int => $this->summary['failed_jobs']),
                    TextEntry::make('open_deletion')->label('Deletion')->state(fn (): ?string => $this->summary['open_deletion']?->label())->placeholder('None open'),
                    TextEntry::make('created_at')->label('Created')->state(fn (): ?string => $this->tenant->created_at?->toDayDateTimeString()),
                ]),
            Section::make('Usage against plan limits')
                ->schema([
                    KeyValueEntry::make('usage')->hiddenLabel()->keyLabel('Limit')->valueLabel('Used / allowed')
                        ->state(fn (): array => collect($this->summary['usage'])->mapWithKeys(fn (array $line): array => [$line['label'] => "{$line['used']} / {$line['allowed']}".($line['over'] ? ' — over limit' : '')])->all()),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (Action|ActionGroup $action): Action|ActionGroup => $action instanceof Action ? $action->after(fn () => $this->forgetTenant()) : $action, [
            ActionGroup::make([
                $this->lifecycleAction('suspend', 'Suspend', 'warning', [TenantStatus::Trial, TenantStatus::Active, TenantStatus::PastDue], fn (Tenant $tenant, string $reason, User $operator) => app(TenantAdministrationService::class)->suspend($tenant, $reason, $operator)),
                $this->lifecycleAction('activate', 'Activate', 'success', [TenantStatus::Trial, TenantStatus::PastDue, TenantStatus::Suspended], fn (Tenant $tenant, string $reason, User $operator) => app(TenantAdministrationService::class)->activate($tenant, $reason, $operator)),
                $this->lifecycleAction('cancel', 'Cancel', 'danger', [TenantStatus::Trial, TenantStatus::Active, TenantStatus::Suspended], fn (Tenant $tenant, string $reason, User $operator) => app(TenantAdministrationService::class)->cancel($tenant, $reason, $operator)),
                Action::make('extendTrial')
                    ->label('Extend trial')
                    ->icon('heroicon-o-clock')
                    ->visible(fn (): bool => self::allows(PlatformCapability::TenantsManage) && in_array($this->tenant->status, [TenantStatus::Trial, TenantStatus::Suspended], true))
                    ->schema([
                        TextInput::make('days')->numeric()->integer()->minValue(1)->maxValue(90)->required(),
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantAdministrationService::class)->extendTrial($this->tenant, (int) $data['days'], (string) $data['reason'], $operator), 'Trial extended'))
                    ->after(fn () => $this->forgetTenant()),
            ])->label('Lifecycle')->icon('heroicon-o-arrow-path')->button()->color('gray'),
            Action::make('transferOwnership')
                ->label('Transfer ownership')
                ->icon('heroicon-o-key')
                ->visible(fn (): bool => self::allows(PlatformCapability::TenantsManage))
                ->modalDescription('The new owner must be an active member who already holds the CHRO role. Ownership moves atomically; the previous owner keeps their membership and roles.')
                ->fillForm(fn (): array => ['expected_owner_id' => $this->summary['owner']?->getKey()])
                ->schema([
                    Select::make('new_owner_id')->label('New owner')->options(fn (): array => app(PlatformDirectory::class)->memberOptions($this->tenant))->searchable()->required(),
                    Textarea::make('reason')->required()->maxLength(255),
                    // Submitted with the form (a hidden *field* would not be): the owner as it was when
                    // the form opened, so a transfer decided on a stale view is refused.
                    Hidden::make('expected_owner_id'),
                ])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantOwnershipService::class)->transfer(
                    $this->tenant,
                    User::query()->findOrFail((int) $data['new_owner_id']),
                    (string) $data['reason'],
                    $operator,
                    filled($data['expected_owner_id'] ?? null) ? User::query()->find((int) $data['expected_owner_id']) : null,
                ), 'Ownership transferred')),
            Action::make('requestSupport')
                ->label('Request support access')
                ->icon('heroicon-o-lifebuoy')
                ->visible(fn (): bool => self::allows(PlatformCapability::SupportManage))
                ->modalDescription('The tenant\'s access administrators are asked to approve. Nothing opens until they do; it ends on its own.')
                ->schema([
                    CheckboxList::make('scopes')->options(collect(SupportScope::cases())->mapWithKeys(fn (SupportScope $scope): array => [$scope->value => $scope->label()])->all())->required(),
                    TextInput::make('minutes')->numeric()->integer()->minValue(15)->maxValue((int) config('platform.support.max_minutes', 480))->default(60)->required(),
                    Textarea::make('reason')->label('Reason (ticket)')->required()->maxLength(255),
                ])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(SupportAccessService::class)->request(
                    $this->tenant,
                    array_map(fn (string $scope): SupportScope => SupportScope::from($scope), (array) $data['scopes']),
                    (int) $data['minutes'],
                    (string) $data['reason'],
                    $operator,
                ), 'Support access requested')),
            Action::make('requestExport')
                ->label('Compliance export')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->visible(fn (): bool => self::allows(PlatformCapability::ComplianceManage))
                ->modalDescription('Exports this tenant\'s records (credentials excluded) and a manifest of its files into a private archive that expires. Recorded and audited.')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(ComplianceExportService::class)->request($this->tenant, (string) $data['reason'], $operator), 'Export requested')),
            Action::make('requestDeletion')
                ->label('Request deletion')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => self::allows(PlatformCapability::DeletionManage) && $this->tenant->status === TenantStatus::Cancelled && $this->summary['open_deletion'] === null)
                ->modalDescription('A second operator approves; the tenant then waits out the grace period before its data is purged. It can be cancelled until the purge starts.')
                ->schema([
                    TextInput::make('confirm')->label('Type the tenant\'s slug to confirm')->required()->in(fn (): array => [$this->tenant->slug]),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantDeletionService::class)->request($this->tenant, (string) $data['reason'], $operator), 'Deletion requested')),
            Action::make('audit')
                ->label('Audit')
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('gray')
                ->visible(fn (): bool => self::allows(PlatformCapability::AuditView))
                ->url(fn (): string => PlatformAudit::getUrl(['tenant' => $this->tenantId])),
        ]);
    }

    /**
     * @param  list<TenantStatus>  $from
     * @param  callable(Tenant, string, User): Tenant  $change
     */
    private function lifecycleAction(string $name, string $label, string $color, array $from, callable $change): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (): bool => self::allows(PlatformCapability::TenantsManage) && in_array($this->tenant->status, $from, true))
            ->schema([Textarea::make('reason')->required()->maxLength(255)])
            ->action(fn (array $data) => self::perform(fn (User $operator) => $change($this->tenant, (string) $data['reason'], $operator), "{$label}: done"))
            ->after(fn () => $this->forgetTenant());
    }
}
