<?php

namespace App\Filament\Platform\Pages;

use App\Enums\BillingInterval;
use App\Enums\Entitlement;
use App\Enums\EntitlementType;
use App\Enums\PlatformCapability;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\SupportScope;
use App\Enums\TenantStatus;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Filament\Platform\Widgets\TenantMembers;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Platform\ComplianceExportService;
use App\Services\Platform\PlatformDirectory;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\TenantAdministrationService;
use App\Services\Platform\TenantCommercialService;
use App\Services\Platform\TenantDeletionService;
use App\Services\Platform\TenantOwnershipService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * SaaS-5 + platform commercial UI: one tenant's control centre — overview, commercial state,
 * subscription and billing, members, configuration, usage, lifecycle, audit and health — and the
 * platform's actions on it. Each action is shown by capability and decided again by its service
 * (authorisation, state, locks, audit). Reads come from PlatformDirectory: metadata and counts,
 * never the tenant's business records.
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
        unset($this->tenant, $this->summary, $this->assignment, $this->entitlements, $this->subscription, $this->recentInvoices, $this->recentPayments, $this->invitations, $this->configuration, $this->health, $this->recentAudit, $this->recentEvents);
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

    /**
     * @return array{plan: string, code: string, version: int, since: ?string, source: ?string, reason: ?string, by: ?string}|null
     */
    #[Computed]
    public function assignment(): ?array
    {
        return app(PlatformDirectory::class)->currentAssignment($this->tenant);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function entitlements(): array
    {
        return app(PlatformDirectory::class)->entitlements($this->tenant);
    }

    #[Computed]
    public function subscription(): ?BillingSubscription
    {
        return app(PlatformDirectory::class)->liveSubscription($this->tenant);
    }

    /**
     * @return list<array<string, ?string>>
     */
    #[Computed]
    public function recentInvoices(): array
    {
        return app(PlatformDirectory::class)->invoices()->where('tenant_id', $this->tenantId)->latest('id')->limit(5)->get()
            ->map(fn (BillingInvoice $invoice): array => [
                'number' => $invoice->number,
                'status' => $invoice->status->label(),
                'total' => $invoice->total()->format(),
                'due' => $invoice->amountDue()->format(),
                'due_at' => $invoice->due_at?->toFormattedDateString(),
            ])->all();
    }

    /**
     * @return list<array<string, ?string>>
     */
    #[Computed]
    public function recentPayments(): array
    {
        return app(PlatformDirectory::class)->payments()->where('tenant_id', $this->tenantId)->latest('id')->limit(5)->get()
            ->map(fn (BillingPayment $payment): array => [
                'invoice' => $payment->invoice?->number,
                'amount' => $payment->amount()->format(),
                'status' => $payment->status->label(),
                'reference' => $payment->external_reference ?? $payment->provider_payment_ref ?? $payment->reference,
                'date' => ($payment->completed_at ?? $payment->attempted_at)?->toFormattedDateString(),
            ])->all();
    }

    /**
     * @return list<array{email: string, name: ?string, expires_at: string, invited_at: string}>
     */
    #[Computed]
    public function invitations(): array
    {
        return app(PlatformDirectory::class)->pendingInvitations($this->tenant);
    }

    /**
     * @return array<string, array<string, int>>
     */
    #[Computed]
    public function configuration(): array
    {
        return app(PlatformDirectory::class)->configuration($this->tenant);
    }

    /**
     * @return list<array{label: string, value: string, state: string}>
     */
    #[Computed]
    public function health(): array
    {
        return app(PlatformDirectory::class)->health($this->tenant, $this->summary);
    }

    /**
     * @return list<array<string, ?string>>
     */
    #[Computed]
    public function recentAudit(): array
    {
        return app(PlatformDirectory::class)->recentAudit($this->tenant)->map(fn ($entry): array => [
            'when' => $entry->created_at?->toDayDateTimeString(),
            'since' => $entry->created_at?->diffForHumans(),
            'action' => $entry->action,
            'actor' => $entry->actor_kind,
            'by' => $entry->user?->name,
            'reason' => $entry->reason,
        ])->all();
    }

    /**
     * @return list<array<string, ?string>>
     */
    #[Computed]
    public function recentEvents(): array
    {
        return app(PlatformDirectory::class)->recentEvents($this->tenant)->map(fn ($event): array => [
            'when' => $event->occurred_at?->toDayDateTimeString(),
            'severity' => $event->severity->label(),
            'title' => $event->title,
            'acknowledged' => $event->acknowledged_at === null ? 'No' : 'Yes',
        ])->all();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->tenant->name;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('tenant')->key('tenantTabs')->persistTabInQueryString()->tabs([
                Tab::make('Overview')->key('overview')->icon('heroicon-o-building-office-2')->schema($this->overviewTab()),
                Tab::make('Commercial')->key('commercial')->icon('heroicon-o-rectangle-stack')->schema($this->commercialTab()),
                Tab::make('Subscription & billing')->key('billing')->icon('heroicon-o-banknotes')
                    ->visible(fn (): bool => $this->permits(PlatformCapability::CommercialManage))
                    ->schema($this->billingTab()),
                Tab::make('Members')->key('members')->icon('heroicon-o-users')
                    ->visible(fn (): bool => $this->permits(PlatformCapability::TenantsManage))
                    ->schema($this->membersTab()),
                Tab::make('Configuration')->key('configuration')->icon('heroicon-o-adjustments-horizontal')->schema($this->configurationTab()),
                Tab::make('Usage')->key('usage')->icon('heroicon-o-chart-bar')->schema($this->usageTab()),
                Tab::make('Lifecycle')->key('lifecycle')->icon('heroicon-o-arrow-path')->schema($this->lifecycleTab()),
                Tab::make('Audit')->key('audit')->icon('heroicon-o-document-magnifying-glass')
                    ->visible(fn (): bool => $this->permits(PlatformCapability::AuditView))
                    ->schema($this->auditTab()),
                Tab::make('Health')->key('health')->icon('heroicon-o-heart')->schema($this->healthTab()),
            ]),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function overviewTab(): array
    {
        return [
            Section::make('Tenant')
                ->columns(3)
                ->schema([
                    TextEntry::make('slug')->state(fn (): string => $this->tenant->slug),
                    TextEntry::make('status')->badge()
                        ->state(fn (): string => $this->tenant->effectiveStatus()->label())
                        ->color(fn (): string => $this->tenant->effectiveStatus()->color()),
                    TextEntry::make('status_reason')->label('Status reason')->state(fn (): ?string => $this->tenant->status_reason)->placeholder('—'),
                    TextEntry::make('legal_name')->label('Legal name')->state(fn (): ?string => $this->tenant->legal_name)->placeholder('—'),
                    TextEntry::make('plan')->state(fn (): ?string => $this->assignment === null ? null : "{$this->assignment['plan']} · v{$this->assignment['version']}")->placeholder('None'),
                    TextEntry::make('subscription')->badge()
                        ->state(fn (): ?string => $this->subscription?->status->label())
                        ->color(fn (): string => Tenants::subscriptionColor($this->subscription?->status))
                        ->placeholder('None'),
                    TextEntry::make('owner')->state(fn (): ?string => $this->summary['owner'] instanceof User ? "{$this->summary['owner']->name} ({$this->summary['owner']->email})" : null)->placeholder('No owner recorded'),
                    TextEntry::make('members')->label('Active members / pending invitations')->state(fn (): string => "{$this->summary['active_members']} / {$this->summary['invited_members']}"),
                    TextEntry::make('trial_ends_at')->label('Trial ends')->state(fn (): ?string => $this->tenant->trial_ends_at?->toDayDateTimeString())->placeholder('—'),
                    TextEntry::make('access_ends_at')->label('Access ends')->state(fn (): ?string => $this->tenant->access_ends_at?->toDayDateTimeString())->placeholder('—'),
                    TextEntry::make('failed_jobs')->label('Failed background jobs')->state(fn (): int => $this->summary['failed_jobs']),
                    TextEntry::make('open_deletion')->label('Deletion')->state(fn (): ?string => $this->summary['open_deletion']?->label())->placeholder('None open'),
                    TextEntry::make('created_at')->label('Created')->state(fn (): ?string => $this->tenant->created_at?->toDayDateTimeString()),
                    TextEntry::make('updated_at')->label('Updated')->state(fn (): ?string => $this->tenant->updated_at?->toDayDateTimeString()),
                    TextEntry::make('last_platform_action')->label('Latest platform action')
                        ->state(fn (): ?string => ($latest = $this->recentAudit[0] ?? null) === null ? null : "{$latest['action']} · {$latest['since']}")
                        ->placeholder('None recorded'),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function commercialTab(): array
    {
        return [
            Section::make('Plan')
                ->description('Changed with Commercial → Change plan. Published plan versions never change; a tenant moves between versions.')
                ->columns(3)
                ->schema([
                    TextEntry::make('assignment_plan')->label('Plan')->state(fn (): ?string => $this->assignment['plan'] ?? null)->placeholder('No plan assigned'),
                    TextEntry::make('assignment_version')->label('Version')->state(fn (): ?string => isset($this->assignment['version']) ? 'v'.$this->assignment['version'] : null)->placeholder('—'),
                    TextEntry::make('assignment_since')->label('Assigned')->state(fn (): ?string => $this->assignment['since'] ?? null)->dateTime()->placeholder('—'),
                    TextEntry::make('assignment_source')->label('Source')->state(fn (): ?string => $this->assignment['source'] ?? null)->placeholder('—'),
                    TextEntry::make('assignment_by')->label('Assigned by')->state(fn (): ?string => $this->assignment['by'] ?? null)->placeholder('Console or system'),
                    TextEntry::make('assignment_reason')->label('Reason')->state(fn (): ?string => $this->assignment['reason'] ?? null)->placeholder('—'),
                ]),
            Section::make('Entitlements')
                ->description('What the plan grants, any override, and what the entitlement service decides for the tenant now.')
                ->schema([
                    RepeatableEntry::make('entitlement_lines')->hiddenLabel()
                        ->state(fn (): array => array_map(fn (array $line): array => [
                            'label' => $line['entitlement']->label(),
                            'plan' => $line['plan'],
                            'override' => $line['override'] === null ? null : $line['override'].' — '.trim(($line['override_reason'] ?? '').($line['override_ends'] !== null ? ' · until '.CarbonImmutable::parse($line['override_ends'])->toDayDateTimeString() : '')),
                            'effective' => $line['effective'],
                            'source' => ucfirst($line['source']),
                        ], $this->entitlements))
                        ->table([
                            TableColumn::make('Entitlement'),
                            TableColumn::make('Plan'),
                            TableColumn::make('Override'),
                            TableColumn::make('Effective'),
                            TableColumn::make('Decided by'),
                        ])
                        ->schema([
                            TextEntry::make('label'),
                            TextEntry::make('plan'),
                            TextEntry::make('override')->placeholder('—')->wrap(),
                            TextEntry::make('effective')->weight('bold'),
                            TextEntry::make('source'),
                        ]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function billingTab(): array
    {
        return [
            Section::make('Subscription')
                ->description('Prices, invoices and payment state are SaaS billing records; amounts are shown in their own currency, as stored.')
                ->columns(3)
                ->schema([
                    TextEntry::make('subscription_none')->hiddenLabel()->state('No live subscription. Use Billing → Subscribe to start one.')
                        ->visible(fn (): bool => $this->subscription === null)->columnSpanFull(),
                    TextEntry::make('subscription_plan')->label('Plan')->state(fn (): ?string => $this->subscription?->planVersion?->label())->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_status')->label('Status')->badge()
                        ->state(fn (): ?string => $this->subscription?->status->label())
                        ->color(fn (): string => Tenants::subscriptionColor($this->subscription?->status))
                        ->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_price')->label('Price')
                        ->state(fn (): ?string => $this->subscription === null ? null : $this->subscription->money()->format().' / '.$this->subscription->interval->label())
                        ->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_source')->label('Billed')->state(fn (): ?string => $this->subscription?->source->name)->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_contract')->label('Contract reference')->state(fn (): ?string => $this->subscription?->contract_reference)->placeholder('—')->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_started')->label('Active since')->state(fn (): ?string => $this->subscription?->activated_at)->dateTime()->placeholder('—')->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_period')->label('Current period')
                        ->state(fn (): ?string => $this->subscription?->current_period_start === null ? null : self::period($this->subscription->current_period_start, $this->subscription->current_period_end))
                        ->placeholder('—')->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_trial')->label('Trial ends')->state(fn (): ?string => $this->subscription?->trial_end)->dateTime()->placeholder('—')->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_cancel')->label('Ends at')->state(fn (): ?string => $this->subscription?->cancel_at)->dateTime()->placeholder('—')->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_past_due')->label('Past due since')->state(fn (): ?string => $this->subscription?->past_due_since)->dateTime()->placeholder('—')->visible(fn (): bool => $this->subscription !== null),
                    TextEntry::make('subscription_grace')->label('Grace ends')->state(fn (): ?string => $this->subscription?->grace_ends_at)->dateTime()->placeholder('—')->visible(fn (): bool => $this->subscription !== null),
                ]),
            Section::make('Recent invoices')
                ->headerActions([Action::make('allInvoices')->label('All invoices')->color('gray')->url(fn (): string => Invoices::getUrl(['tenant' => $this->tenantId]))])
                ->schema([
                    RepeatableEntry::make('recent_invoices')->hiddenLabel()
                        ->state(fn (): array => $this->recentInvoices)
                        ->placeholder('No invoices')
                        ->table([TableColumn::make('Invoice'), TableColumn::make('Status'), TableColumn::make('Total'), TableColumn::make('Balance'), TableColumn::make('Due')])
                        ->schema([TextEntry::make('number'), TextEntry::make('status')->badge(), TextEntry::make('total'), TextEntry::make('due'), TextEntry::make('due_at')->placeholder('—')]),
                ]),
            Section::make('Recent payments')
                ->headerActions([Action::make('allPayments')->label('All payments')->color('gray')->url(fn (): string => Payments::getUrl(['tenant' => $this->tenantId]))])
                ->schema([
                    RepeatableEntry::make('recent_payments')->hiddenLabel()
                        ->state(fn (): array => $this->recentPayments)
                        ->placeholder('No payments')
                        ->table([TableColumn::make('Invoice'), TableColumn::make('Amount'), TableColumn::make('Status'), TableColumn::make('Reference'), TableColumn::make('Date')])
                        ->schema([TextEntry::make('invoice')->placeholder('—'), TextEntry::make('amount'), TextEntry::make('status')->badge(), TextEntry::make('reference'), TextEntry::make('date')->placeholder('—')]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function membersTab(): array
    {
        return [
            Livewire::make(TenantMembers::class, fn (): array => ['tenantId' => $this->tenantId])->key('tenant-members')->lazy(),
            Section::make('Pending invitations')
                ->schema([
                    RepeatableEntry::make('invitations')->hiddenLabel()
                        ->state(fn (): array => $this->invitations)
                        ->placeholder('No pending invitations')
                        ->table([TableColumn::make('Email'), TableColumn::make('Name'), TableColumn::make('Invited'), TableColumn::make('Expires')])
                        ->schema([TextEntry::make('email'), TextEntry::make('name')->placeholder('—'), TextEntry::make('invited_at')->dateTime(), TextEntry::make('expires_at')->dateTime()]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function configurationTab(): array
    {
        $configuration = fn (): array => $this->configuration;

        return [
            Section::make('Profile')
                ->description('Set when the tenant was provisioned. The tenant administers its own organisation in its own panel.')
                ->columns(3)
                ->schema([
                    TextEntry::make('profile_country')->label('Country')->state(fn (): ?string => $this->tenant->country)->placeholder('—'),
                    TextEntry::make('profile_timezone')->label('Timezone')->state(fn (): ?string => $this->tenant->timezone)->placeholder('—'),
                    TextEntry::make('profile_currency')->label('Currency')->state(fn (): ?string => $this->tenant->currency)->placeholder('—'),
                    TextEntry::make('profile_locale')->label('Locale')->state(fn (): ?string => $this->tenant->locale)->placeholder('—'),
                    TextEntry::make('profile_mfa')->label('MFA required for every member')->state(fn (): string => $this->tenant->mfa_required ? 'Yes' : 'No'),
                ]),
            Section::make('Setup')
                ->description('Counts only — never the records themselves.')
                ->columns(2)
                ->schema([
                    KeyValueEntry::make('configuration_organisation')->label('Organisation')->keyLabel('Item')->valueLabel('Count')->state(fn (): array => self::counts($configuration()['Organisation'])),
                    KeyValueEntry::make('configuration_recruitment')->label('Recruitment')->keyLabel('Item')->valueLabel('Count')->state(fn (): array => self::counts($configuration()['Recruitment'])),
                    KeyValueEntry::make('configuration_setup')->label('Templates and automation')->keyLabel('Item')->valueLabel('Count')->state(fn (): array => self::counts($configuration()['Setup'])),
                    KeyValueEntry::make('configuration_integrations')->label('Integrations')->keyLabel('Item')->valueLabel('Count')->state(fn (): array => self::counts($configuration()['Integrations'])),
                ]),
            Section::make('Features')
                ->schema([
                    KeyValueEntry::make('features')->hiddenLabel()->keyLabel('Feature')->valueLabel('For this tenant now')
                        ->state(fn (): array => collect($this->entitlements)->filter(fn (array $line): bool => $line['entitlement']->type() === EntitlementType::Feature)->mapWithKeys(fn (array $line): array => [$line['entitlement']->label() => $line['effective']])->all()),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function usageTab(): array
    {
        return [
            Section::make('Usage against plan limits')
                ->schema([
                    RepeatableEntry::make('usage_lines')->hiddenLabel()
                        ->state(fn (): array => array_map(fn (array $line): array => [
                            'label' => $line['label'],
                            'used' => (string) $line['used'],
                            'allowed' => $line['allowed'],
                            'utilisation' => ctype_digit($line['allowed']) && (int) $line['allowed'] > 0 ? intdiv($line['used'] * 100, (int) $line['allowed']).'%' : '—',
                            'state' => $line['over'] ? 'Over limit' : 'Within limit',
                        ], $this->summary['usage']))
                        ->placeholder('No limits')
                        ->table([TableColumn::make('Limit'), TableColumn::make('Used'), TableColumn::make('Allowed'), TableColumn::make('Utilisation'), TableColumn::make('State')])
                        ->schema([
                            TextEntry::make('label'),
                            TextEntry::make('used'),
                            TextEntry::make('allowed'),
                            TextEntry::make('utilisation'),
                            TextEntry::make('state')->badge()->color(fn (string $state): string => $state === 'Over limit' ? 'danger' : 'success'),
                        ]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function lifecycleTab(): array
    {
        return [
            Section::make('Lifecycle')
                ->description('Changed with the Lifecycle menu above: each change asks for a reason and a confirmation. Past due is set by billing, never by hand.')
                ->columns(3)
                ->schema([
                    TextEntry::make('lifecycle_status')->label('Status')->badge()
                        ->state(fn (): string => $this->tenant->effectiveStatus()->label())
                        ->color(fn (): string => $this->tenant->effectiveStatus()->color()),
                    TextEntry::make('lifecycle_reason')->label('Reason')->state(fn (): ?string => $this->tenant->status_reason)->placeholder('—'),
                    TextEntry::make('lifecycle_changed')->label('Since')->state(fn (): ?string => $this->tenant->status_changed_at?->toDayDateTimeString())->placeholder('—'),
                    TextEntry::make('lifecycle_provisioned')->label('Provisioned')->state(fn (): ?string => $this->tenant->provisioned_at?->toDayDateTimeString())->placeholder('Not recorded'),
                    TextEntry::make('lifecycle_provisioning_error')->label('Provisioning error')->state(fn (): ?string => $this->tenant->provisioning_error)->placeholder('None'),
                    TextEntry::make('lifecycle_trial')->label('Trial')->state(fn (): ?string => $this->tenant->trial_ends_at === null ? null : self::period($this->tenant->trial_started_at, $this->tenant->trial_ends_at))->placeholder('No trial'),
                    TextEntry::make('lifecycle_access_ends')->label('Access ends')->state(fn (): ?string => $this->tenant->access_ends_at?->toDayDateTimeString())->placeholder('—'),
                    TextEntry::make('lifecycle_deletion')->label('Deletion')->state(fn (): ?string => $this->summary['open_deletion']?->label())->placeholder('None open'),
                    TextEntry::make('lifecycle_subscription')->label('Subscription')->state(fn (): ?string => $this->subscription?->status->label())->placeholder('None'),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function auditTab(): array
    {
        return [
            Section::make('Latest platform actions')
                ->headerActions([Action::make('fullAudit')->label('Full audit')->color('gray')->url(fn (): string => PlatformAudit::getUrl(['tenant' => $this->tenantId]))])
                ->schema([
                    RepeatableEntry::make('recent_audit')->hiddenLabel()
                        ->state(fn (): array => $this->recentAudit)
                        ->placeholder('No platform actions recorded')
                        ->table([TableColumn::make('When'), TableColumn::make('Action'), TableColumn::make('Actor'), TableColumn::make('By'), TableColumn::make('Reason')])
                        ->schema([TextEntry::make('when'), TextEntry::make('action'), TextEntry::make('actor')->badge(), TextEntry::make('by')->placeholder('—'), TextEntry::make('reason')->placeholder('—')->limit(60)]),
                ]),
            Section::make('Latest platform events')
                ->schema([
                    RepeatableEntry::make('recent_events')->hiddenLabel()
                        ->state(fn (): array => $this->recentEvents)
                        ->placeholder('No platform events')
                        ->table([TableColumn::make('When'), TableColumn::make('Severity'), TableColumn::make('Event'), TableColumn::make('Acknowledged')])
                        ->schema([TextEntry::make('when'), TextEntry::make('severity')->badge(), TextEntry::make('title'), TextEntry::make('acknowledged')]),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function healthTab(): array
    {
        return [
            Section::make('Health')
                ->description('Read from what the platform already records — lifecycle, billing, usage, jobs, events, support and deletion.')
                ->schema([
                    RepeatableEntry::make('health')->hiddenLabel()
                        ->state(fn (): array => $this->health)
                        ->table([TableColumn::make('Indicator'), TableColumn::make('Now'), TableColumn::make('State')])
                        ->schema([
                            TextEntry::make('label'),
                            TextEntry::make('value'),
                            TextEntry::make('state')->badge()
                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                    'ok' => 'OK',
                                    'attention' => 'Needs attention',
                                    default => 'Problem',
                                })
                                ->color(fn (string $state): string => match ($state) {
                                    'ok' => 'success',
                                    'attention' => 'warning',
                                    default => 'danger',
                                }),
                        ]),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (Action|ActionGroup $action): Action|ActionGroup => $action instanceof Action ? $action->after(fn () => $this->forgetTenant()) : $action, [
            ActionGroup::make([
                $this->lifecycleAction('suspend', 'Suspend', 'warning', [TenantStatus::Trial, TenantStatus::Active, TenantStatus::PastDue], 'Every member of the tenant loses access and its background work pauses, immediately. Reversible: Activate restores access; no data is changed.', fn (Tenant $tenant, string $reason, User $operator) => app(TenantAdministrationService::class)->suspend($tenant, $reason, $operator)),
                $this->lifecycleAction('activate', 'Activate', 'success', [TenantStatus::Trial, TenantStatus::PastDue, TenantStatus::Suspended], 'Members regain access and background work resumes, immediately. Reversible: the tenant can be suspended again.', fn (Tenant $tenant, string $reason, User $operator) => app(TenantAdministrationService::class)->activate($tenant, $reason, $operator)),
                $this->lifecycleAction('cancel', 'Cancel', 'danger', [TenantStatus::Trial, TenantStatus::Active, TenantStatus::Suspended], 'The tenant is closed immediately: members lose access and it cannot be reactivated. Its data stays until a deletion is requested, approved by a second operator and its grace period has passed. Not reversible.', fn (Tenant $tenant, string $reason, User $operator) => app(TenantAdministrationService::class)->cancel($tenant, $reason, $operator)),
                Action::make('extendTrial')
                    ->label('Extend trial')
                    ->icon('heroicon-o-clock')
                    ->visible(fn (): bool => $this->permits(PlatformCapability::TenantsManage) && in_array($this->tenant->status, [TenantStatus::Trial, TenantStatus::Suspended], true))
                    ->schema([
                        TextInput::make('days')->numeric()->integer()->minValue(1)->maxValue(90)->required(),
                        Textarea::make('reason')->required()->maxLength(255),
                    ])
                    ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantAdministrationService::class)->extendTrial($this->tenant, (int) $data['days'], (string) $data['reason'], $operator), 'Trial extended'))
                    ->after(fn () => $this->forgetTenant()),
            ])->label('Lifecycle')->icon('heroicon-o-arrow-path')->button()->color('gray'),
            ActionGroup::make($this->commercialActions())->label('Commercial')->icon('heroicon-o-rectangle-stack')->button()->color('gray'),
            ActionGroup::make($this->billingActions())->label('Billing')->icon('heroicon-o-banknotes')->button()->color('gray'),
            Action::make('transferOwnership')
                ->label('Transfer ownership')
                ->icon('heroicon-o-key')
                ->visible(fn (): bool => $this->permits(PlatformCapability::TenantsManage))
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
                ->visible(fn (): bool => $this->permits(PlatformCapability::SupportManage))
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
                ->visible(fn (): bool => $this->permits(PlatformCapability::ComplianceManage))
                ->modalDescription('Exports this tenant\'s records (credentials excluded) and a manifest of its files into a private archive that expires. Recorded and audited.')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(ComplianceExportService::class)->request($this->tenant, (string) $data['reason'], $operator), 'Export requested')),
            Action::make('requestDeletion')
                ->label('Request deletion')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->permits(PlatformCapability::DeletionManage) && $this->tenant->status === TenantStatus::Cancelled && $this->summary['open_deletion'] === null)
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
                ->visible(fn (): bool => $this->permits(PlatformCapability::AuditView))
                ->url(fn (): string => PlatformAudit::getUrl(['tenant' => $this->tenantId])),
        ]);
    }

    /**
     * Plan and entitlement actions (SaaS-3, through TenantCommercialService).
     *
     * @return list<Action>
     */
    private function commercialActions(): array
    {
        $manages = fn (): bool => $this->permits(PlatformCapability::TenantsManage) && ! in_array($this->tenant->status, [TenantStatus::Provisioning, TenantStatus::Cancelled, TenantStatus::DeletionPending, TenantStatus::Deleted], true);

        return [
            Action::make('changePlan')
                ->label('Change plan')
                ->icon('heroicon-o-arrows-right-left')
                ->visible($manages)
                ->modalHeading(fn (): string => "Change {$this->tenant->name}'s plan")
                ->modalDescription(fn (): string => 'WHAT: the tenant moves to the chosen plan at its latest published version, and its entitlements change as compared below; overrides still apply. WHEN: immediately. CURRENT: '.($this->assignment === null ? 'no plan' : "{$this->assignment['plan']} v{$this->assignment['version']}").'. REVERSIBLE: yes — assign the previous plan again. A lower limit refuses new usage above it; existing data is kept.')
                ->schema(fn (): array => [
                    Select::make('plan')->label('New plan')->required()->live()
                        ->options(collect(app(PlatformDirectory::class)->offeredPlans())->map(fn (array $plan): string => $plan['label'])->all()),
                    KeyValueEntry::make('comparison')->label('Plan values: current → new')->keyLabel('Entitlement')->valueLabel('Change')
                        ->visible(fn (Get $get): bool => filled($get('plan')))
                        ->state(fn (Get $get): array => $this->planComparison((string) $get('plan'))),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->modalSubmitActionLabel('Change plan')
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->assignPlan($this->tenant, (string) $data['plan'], (string) $data['reason'], $operator), 'Plan changed')),
            Action::make('setOverride')
                ->label('Set entitlement override')
                ->icon('heroicon-o-adjustments-vertical')
                ->visible($manages)
                ->modalDescription(fn (): string => "WHAT: one entitlement of {$this->tenant->name} takes the value you set instead of the plan's, until you remove it or it ends. WHEN: immediately. REVERSIBLE: yes — remove the override.")
                ->schema([
                    Select::make('entitlement')->required()->live()
                        ->options(collect(Entitlement::cases())->mapWithKeys(fn (Entitlement $entitlement): array => [$entitlement->value => $entitlement->label()])->all()),
                    TextEntry::make('override_current')->label('Now')
                        ->visible(fn (Get $get): bool => filled($get('entitlement')))
                        ->state(fn (Get $get): ?string => ($line = $this->entitlementLine((string) $get('entitlement'))) === null ? null : "Plan: {$line['plan']} · Override: ".($line['override'] ?? 'none')." · Effective: {$line['effective']}"),
                    Toggle::make('enabled')->label('Included')
                        ->visible(fn (Get $get): bool => Entitlement::tryFrom((string) $get('entitlement'))?->type() === EntitlementType::Feature),
                    Toggle::make('unlimited')->label('Unlimited')->live()
                        ->visible(fn (Get $get): bool => Entitlement::tryFrom((string) $get('entitlement'))?->type() === EntitlementType::Limit),
                    TextInput::make('limit')->numeric()->integer()->minValue(0)
                        ->required(fn (Get $get): bool => Entitlement::tryFrom((string) $get('entitlement'))?->type() === EntitlementType::Limit && ! $get('unlimited'))
                        ->visible(fn (Get $get): bool => Entitlement::tryFrom((string) $get('entitlement'))?->type() === EntitlementType::Limit && ! $get('unlimited')),
                    DateTimePicker::make('ends_at')->label('Ends (optional)')->after('now'),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $entitlement = Entitlement::from((string) $data['entitlement']);
                    $value = match (true) {
                        $entitlement->type() === EntitlementType::Feature => (bool) ($data['enabled'] ?? false),
                        (bool) ($data['unlimited'] ?? false) => TenantCommercialService::UNLIMITED,
                        default => (int) $data['limit'],
                    };

                    self::perform(fn (User $operator) => app(TenantCommercialService::class)->setOverride($this->tenant, $entitlement, $value, (string) $data['reason'], filled($data['ends_at'] ?? null) ? CarbonImmutable::parse((string) $data['ends_at']) : null, $operator), 'Override set');
                }),
            Action::make('removeOverride')
                ->label('Remove entitlement override')
                ->icon('heroicon-o-x-mark')
                ->visible(fn (): bool => $manages() && collect($this->entitlements)->contains(fn (array $line): bool => $line['override'] !== null))
                ->modalDescription(fn (): string => "WHAT: the entitlement goes back to what {$this->tenant->name}'s plan grants. WHEN: immediately. REVERSIBLE: yes — set the override again.")
                ->schema([
                    Select::make('entitlement')->required()
                        ->options(fn (): array => collect($this->entitlements)->filter(fn (array $line): bool => $line['override'] !== null)->mapWithKeys(fn (array $line): array => [$line['entitlement']->value => "{$line['entitlement']->label()} (override: {$line['override']}, plan: {$line['plan']})"])->all()),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->removeOverride($this->tenant, Entitlement::from((string) $data['entitlement']), (string) $data['reason'], $operator), 'Override removed')),
        ];
    }

    /**
     * Subscription actions (SaaS-4, through TenantCommercialService). Invoice and payment actions
     * live on Commercial → Invoices and Payments.
     *
     * @return list<Action>
     */
    private function billingActions(): array
    {
        $commercial = fn (): bool => $this->permits(PlatformCapability::CommercialManage);
        $live = fn (): bool => $commercial() && $this->subscription !== null;

        return [
            Action::make('subscribe')
                ->label('Subscribe')
                ->icon('heroicon-o-plus-circle')
                ->visible(fn (): bool => $commercial() && $this->subscription === null && ! in_array($this->tenant->status, [TenantStatus::Provisioning, TenantStatus::Cancelled, TenantStatus::DeletionPending, TenantStatus::Deleted], true))
                ->modalHeading(fn (): string => "Subscribe {$this->tenant->name}")
                ->modalDescription('WHAT: a subscription starts, billed manually at a current price or under a contract; the plan follows the subscription. WHEN: now. RESULT: during a running trial it starts as Trialing and is first invoiced at the trial\'s end; otherwise a Manual subscription starts as Pending with its first invoice issued now, to be paid and recorded on Invoices; a Contract subscription starts as Active with its first invoice issued now. REVERSIBLE: it can be cancelled; issued invoices stay.')
                ->schema([
                    Radio::make('source')->label('Billing')->required()->live()
                        ->options([SubscriptionSource::Manual->value => 'Manual — at a current price, paid offline', SubscriptionSource::Contract->value => 'Contract — a negotiated amount']),
                    Select::make('price_id')->label('Price')
                        ->options(fn (): array => app(PlatformDirectory::class)->priceOptions())
                        ->required(fn (Get $get): bool => $get('source') === SubscriptionSource::Manual->value)
                        ->visible(fn (Get $get): bool => $get('source') === SubscriptionSource::Manual->value),
                    Select::make('plan')->label('Plan')
                        ->options(fn (): array => collect(app(PlatformDirectory::class)->offeredPlans())->map(fn (array $plan): string => $plan['label'])->all())
                        ->required(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value)
                        ->visible(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value),
                    TextInput::make('amount')->label('Contract amount per period')->regex('/^\d+(\.\d+)?$/')
                        ->helperText('In the currency\'s own decimals; checked against the currency\'s precision.')
                        ->required(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value)
                        ->visible(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value),
                    Select::make('currency')->options(fn (): array => array_combine(Money::currencies(), Money::currencies()))
                        ->required(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value)
                        ->visible(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value),
                    Select::make('interval')->options(collect(BillingInterval::cases())->mapWithKeys(fn (BillingInterval $interval): array => [$interval->value => $interval->label()])->all())
                        ->required(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value)
                        ->visible(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value),
                    TextInput::make('contract_reference')->label('Contract reference')->maxLength(120)
                        ->required(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value)
                        ->visible(fn (Get $get): bool => $get('source') === SubscriptionSource::Contract->value),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->modalSubmitActionLabel('Subscribe')
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->subscribe(
                    $this->tenant,
                    SubscriptionSource::from((string) $data['source']),
                    [
                        'price_id' => isset($data['price_id']) ? (int) $data['price_id'] : null,
                        'plan' => $data['plan'] ?? null,
                        'amount' => $data['amount'] ?? null,
                        'currency' => $data['currency'] ?? null,
                        'interval' => $data['interval'] ?? null,
                        'contract_reference' => $data['contract_reference'] ?? null,
                    ],
                    (string) $data['reason'],
                    $operator,
                ), 'Subscription started')),
            Action::make('changeSubscriptionPlan')
                ->label('Change subscription plan')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (): bool => $live() && ($this->subscription->status->grantsPlan() || $this->subscription->status === SubscriptionStatus::Unpaid))
                ->modalDescription(fn (): string => "WHAT: {$this->tenant->name}'s subscription moves to the chosen price; the plan follows. CURRENT: ".$this->subscriptionLine().'. WHEN: now; the current invoice is not changed and there is no proration — the next invoice uses the new price. REVERSIBLE: yes — change back to a current price of the previous plan.')
                ->schema([
                    Select::make('price_id')->label('New price')->required()
                        ->options(fn (): array => $this->subscription === null ? [] : app(PlatformDirectory::class)->priceOptions($this->subscription->currency, $this->subscription->interval))
                        ->helperText('Only prices in the subscription\'s currency and interval: those never change mid-subscription.'),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->changeSubscriptionPlan($this->tenant, (int) $data['price_id'], (string) $data['reason'], $operator), 'Subscription plan changed')),
            Action::make('cancelAtPeriodEnd')
                ->label('Cancel at period end')
                ->icon('heroicon-o-calendar')
                ->color('warning')
                ->visible(fn (): bool => $live() && in_array($this->subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Cancel {$this->tenant->name}'s subscription at period end")
                ->modalDescription(fn (): string => 'WHAT: the subscription ends when its '.($this->subscription?->status === SubscriptionStatus::Trialing ? 'trial' : 'paid period').' ends'.(($end = $this->subscription?->status === SubscriptionStatus::Trialing ? $this->subscription->current_period_start : $this->subscription?->current_period_end) !== null ? ' ('.$end->toDayDateTimeString().')' : '').'. CURRENT: '.$this->subscriptionLine().'. RESULT: Ending at period end now, then ended; access ends with the period. REVERSIBLE: only by the tenant\'s billing administrator, before the period ends — the platform cannot withdraw it.')
                ->schema([Textarea::make('reason')->required()->maxLength(255)])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->cancelSubscription($this->tenant, false, (string) $data['reason'], $operator), 'Subscription ends at period end')),
            Action::make('cancelNow')
                ->label('Cancel now')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible($live)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Cancel {$this->tenant->name}'s subscription now")
                ->modalDescription(fn (): string => 'WHAT: the subscription is cancelled immediately and the tenant\'s access is suspended. CURRENT: '.$this->subscriptionLine().'. RESULT: Cancelled; issued invoices and payments are kept. REVERSIBLE: no — a new subscription is needed.')
                ->schema([
                    TextInput::make('confirm')->label('Type the tenant\'s slug to confirm')->required()->in(fn (): array => [$this->tenant->slug]),
                    Textarea::make('reason')->required()->maxLength(255),
                ])
                ->action(fn (array $data) => self::perform(fn (User $operator) => app(TenantCommercialService::class)->cancelSubscription($this->tenant, true, (string) $data['reason'], $operator), 'Subscription cancelled')),
        ];
    }

    /**
     * @param  list<TenantStatus>  $from
     * @param  callable(Tenant, string, User): Tenant  $change
     */
    private function lifecycleAction(string $name, string $label, string $color, array $from, string $consequence, callable $change): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription(fn (): string => "{$this->tenant->name} — now {$this->tenant->effectiveStatus()->label()}. {$consequence}")
            ->visible(fn (): bool => $this->permits(PlatformCapability::TenantsManage) && in_array($this->tenant->status, $from, true))
            ->schema([Textarea::make('reason')->required()->maxLength(255)])
            ->action(fn (array $data) => self::perform(fn (User $operator) => $change($this->tenant, (string) $data['reason'], $operator), "{$label}: done"))
            ->after(fn () => $this->forgetTenant());
    }

    /**
     * Current plan value → the chosen plan's value, per entitlement.
     *
     * @return array<string, string>
     */
    private function planComparison(string $planCode): array
    {
        $new = app(PlatformDirectory::class)->offeredPlans()[$planCode]['grants'] ?? [];

        return collect($this->entitlements)->mapWithKeys(function (array $line) use ($new): array {
            $label = $line['entitlement']->label();
            $after = $new[$label] ?? 'Not included';

            return [$label => $line['plan'] === $after ? "{$after} (unchanged)" : "{$line['plan']} → {$after}"];
        })->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entitlementLine(string $key): ?array
    {
        return collect($this->entitlements)->first(fn (array $line): bool => $line['entitlement']->value === $key);
    }

    private function subscriptionLine(): string
    {
        return $this->subscription === null
            ? 'no subscription'
            : "{$this->subscription->status->label()}, {$this->subscription->planVersion?->label()}, {$this->subscription->money()->format()} / {$this->subscription->interval->label()}";
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, string>
     */
    private static function counts(array $counts): array
    {
        return array_map(fn (int $count): string => (string) $count, $counts);
    }

    private static function period(mixed $start, mixed $end): string
    {
        return collect([$start, $end])->map(fn (mixed $date): string => $date === null ? '…' : CarbonImmutable::parse($date)->toFormattedDateString())->implode(' – ');
    }
}
