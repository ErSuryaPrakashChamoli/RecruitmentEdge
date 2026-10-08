<?php

namespace App\Filament\Platform\Pages;

use App\Enums\AccessState;
use App\Enums\PlatformCapability;
use App\Enums\SupportScope;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\AuditLog;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\SupportWorkspace as Workspace;
use DomainException;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * SaaS-5: a support operator's read-only view of one tenant, through that tenant's own grant —
 * never by signing in as anyone. The grant is checked on page load (an audited use) and again on
 * every Livewire update: the moment it ends, is revoked, or the operator loses the support role,
 * the page stops answering (403). One scope at a time; switching scope is another audited use.
 */
class SupportWorkspace extends Page implements HasTable
{
    use InteractsWithPlatform;
    use InteractsWithTable;

    protected static ?string $slug = 'support/{grant}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public int $grantId;

    #[Locked]
    #[Url]
    public string $scope = 'diagnostics';

    public static function canAccess(): bool
    {
        return self::allows(PlatformCapability::SupportManage);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'support-workspace';
    }

    public function mount(int|string $grant): void
    {
        $this->grantId = (int) $grant;
        $this->openScope(SupportScope::tryFrom($this->scope) ?? SupportScope::Diagnostics);
    }

    public function hydrate(): void
    {
        $this->usable();
    }

    #[Computed]
    public function grant(): SupportAccessGrant
    {
        return $this->usable();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Support: '.($this->grant->tenant?->name ?? 'tenant').' — '.SupportScope::from($this->scope)->name;
    }

    public function content(Schema $schema): Schema
    {
        $scope = SupportScope::from($this->scope);

        $banner = Section::make('Support access')
            ->description('Read-only. Every view is recorded in the organisation\'s audit trail.')
            ->columns(3)
            ->schema([
                TextEntry::make('ends')->label('Ends')->state(fn (): ?string => $this->grant->expires_at?->toDayDateTimeString()),
                TextEntry::make('scopes')->label('Covers')->state(fn (): string => implode(', ', (array) $this->grant->scopes)),
                TextEntry::make('reason')->state(fn (): ?string => $this->grant->reason),
            ]);

        if ($scope !== SupportScope::Diagnostics) {
            return $schema->components([$banner, EmbeddedTable::make()]);
        }

        $diagnostics = app(Workspace::class)->diagnostics($this->grantId, self::operator());

        return $schema->components([
            $banner,
            Section::make('Organisation')->columns(3)->schema([
                TextEntry::make('status')->state(fn (): string => $diagnostics['tenant']->effectiveStatus()->label()),
                TextEntry::make('plan')->state(fn (): ?string => $diagnostics['summary']['plan'])->placeholder('None'),
                TextEntry::make('members')->label('Active members')->state(fn (): int => $diagnostics['summary']['active_members']),
                TextEntry::make('billing')->label('Subscription')->state(fn (): ?string => $diagnostics['billing']['status_label'] ?? null)->placeholder('No subscription'),
                TextEntry::make('period_end')->label('Period ends')->state(fn (): ?string => $diagnostics['billing']['period_end'] ?? null)->placeholder('—'),
                TextEntry::make('failed')->label('Failed background jobs')->state(fn (): int => $diagnostics['summary']['failed_jobs']),
            ]),
            Section::make('Usage')->schema([
                KeyValueEntry::make('usage')->hiddenLabel()->keyLabel('Limit')->valueLabel('Used / allowed')
                    ->state(fn (): array => collect($diagnostics['summary']['usage'])->mapWithKeys(fn (array $line): array => [$line['label'] => "{$line['used']} / {$line['allowed']}"])->all()),
            ]),
            Section::make('Failed background work')->schema([
                KeyValueEntry::make('failed_work')->hiddenLabel()->keyLabel('Job')->valueLabel('Failures (last)')
                    ->state(fn (): array => collect($diagnostics['failed_work'])->mapWithKeys(fn (array $job): array => [$job['job'] => "{$job['count']} ({$job['last_failed_at']})"])->all()),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        if ($this->scope === SupportScope::Audit->value) {
            return $table
                ->query(fn (): Builder => app(Workspace::class)->audit($this->grantId, self::operator()))
                ->defaultSort('id', 'desc')
                ->columns([
                    TextColumn::make('created_at')->label('When')->dateTime(),
                    TextColumn::make('action'),
                    TextColumn::make('auditable_type')->label('Subject')->formatStateUsing(fn (?string $state, AuditLog $record): string => class_basename((string) $state).' #'.$record->auditable_id),
                    TextColumn::make('actor_kind')->label('Actor')->badge(),
                    TextColumn::make('user.name')->label('By')->placeholder('—'),
                    TextColumn::make('reason')->limit(60)->placeholder('—'),
                ])
                ->recordUrl(null);
        }

        if ($this->scope !== SupportScope::People->value) {
            // Diagnostics has no table; nothing is read for it.
            return $table->query(fn (): Builder => User::query()->whereRaw('1 = 0'))->columns([TextColumn::make('name')]);
        }

        return $table
            ->query(fn (): Builder => app(Workspace::class)->people($this->grantId, self::operator()))
            ->defaultSort('users.name')
            ->columns([
                TextColumn::make('name')->description(fn (User $record): string => $record->email),
                TextColumn::make('membership_status')->label('Access')->badge()
                    ->formatStateUsing(fn (?string $state): string => AccessState::tryFrom((string) $state)?->label() ?? (string) $state),
                IconColumn::make('is_owner')->label('Owner')->boolean(),
                TextColumn::make('role_names')->label('Roles')->placeholder('None'),
                IconColumn::make('mfa_enrolled')->label('MFA')->boolean(),
                TextColumn::make('last_login_at')->label('Last sign-in')->since()->placeholder('Never'),
            ])
            ->recordUrl(null);
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn (SupportScope $scope): Action => Action::make('scope_'.$scope->value)
            ->label($scope->name)
            ->color($this->scope === $scope->value ? 'primary' : 'gray')
            ->visible(fn (): bool => $this->grant->allows($scope))
            ->action(function () use ($scope): void {
                $this->openScope($scope);
                unset($this->grant);
                $this->resetTable();
            }), SupportScope::cases());
    }

    /**
     * An audited use of the grant for $scope (SupportAccessService::open).
     */
    private function openScope(SupportScope $scope): void
    {
        try {
            app(SupportAccessService::class)->open($this->grantId, $scope, self::operator(), 'workspace:'.$scope->value);
        } catch (DomainException $e) {
            abort(403, $e->getMessage());
        }

        $this->scope = $scope->value;
    }

    private function usable(): SupportAccessGrant
    {
        try {
            return app(SupportAccessService::class)->usableGrant($this->grantId, SupportScope::tryFrom($this->scope) ?? SupportScope::Diagnostics, self::operator());
        } catch (DomainException $e) {
            abort(403, $e->getMessage());
        }
    }
}
