<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\AccessState;
use App\Enums\PlatformCapability;
use App\Filament\Platform\Concerns\InteractsWithPlatform;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\Platform\PlatformDirectory;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Platform commercial UI: one tenant's members on its detail page — name, email, membership state,
 * owner, roles. Identity metadata only (no credential, MFA or session data). The tenant id is
 * locked, and the capability is checked again on every request, not only when the tab renders.
 * Not a dashboard widget (Dashboard lists its widgets explicitly).
 */
class TenantMembers extends TableWidget
{
    use InteractsWithPlatform;

    #[Locked]
    public int $tenantId;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Members';

    public static function canView(): bool
    {
        return self::allows(PlatformCapability::TenantsManage);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function hydrate(): void
    {
        abort_unless(static::canView(), 403);
    }

    #[Computed]
    public function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantId);
    }

    /**
     * @return array<int, list<string>>
     */
    #[Computed]
    public function roles(): array
    {
        return app(PlatformDirectory::class)->memberRoles($this->tenant);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(PlatformDirectory::class)->members($this->tenant))
            ->defaultSort('is_owner', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Name')
                    ->description(fn (TenantMembership $record): ?string => $record->user?->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('status')->label('Membership')->badge()
                    ->formatStateUsing(fn (AccessState $state): string => $state->label())
                    ->color(fn (AccessState $state): string => $state->color()),
                TextColumn::make('is_owner')->label('Owner')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Owner' : '—')
                    ->color(fn (bool $state): string => $state ? 'primary' : 'gray'),
                TextColumn::make('roles')->label('Roles')->wrap()->placeholder('No roles')
                    ->state(fn (TenantMembership $record): ?string => ($this->roles[$record->user_id] ?? []) === [] ? null : implode(', ', $this->roles[$record->user_id])),
                TextColumn::make('joined_at')->label('Joined')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Membership')->options(collect(AccessState::cases())->mapWithKeys(fn (AccessState $state): array => [$state->value => $state->label()])->all()),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No members yet');
    }
}
