<?php

namespace App\Filament\Pages;

use App\Enums\AccessState;
use App\Enums\AiToolCallStatus;
use App\Enums\EmployeeStatus;
use App\Filament\Resources\Users\Actions\UserAccessActions;
use App\Filament\Resources\Users\MfaStatus;
use App\Models\AiToolCall;
use App\Models\EmployeeSeparation;
use App\Models\OwnershipHandoff;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Identity\OwnershipHandoffService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Phase 8.4: the access review — for every login in the reviewer's hierarchy (everyone with
 * hierarchy.view-all): employment, access, roles, manager, MFA, last sign-in, live sessions,
 * pending AI actions, open handoffs and any separation. Needs access.review; read-only apart from
 * the same confirmed, audited access actions the Users screen offers (each re-checked by its
 * service). No global bypass: the list is scoped exactly like every other hierarchy view.
 */
class AccessReview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Access Review';

    protected static ?string $title = 'Access Review';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('access.review');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::reviewQuery())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('employee.employee_code')
                    ->label('Employee')
                    ->placeholder('No employee record'),
                TextColumn::make('employee.status')
                    ->label('Employment')
                    ->badge()
                    ->formatStateUsing(fn (?EmployeeStatus $state): string => $state?->label() ?? '—')
                    ->color(fn (?EmployeeStatus $state): string => $state?->color() ?? 'gray'),
                TextColumn::make('access_status')
                    ->label('Access')
                    ->badge()
                    ->formatStateUsing(fn (AccessState $state): string => $state->label())
                    ->color(fn (AccessState $state): string => $state->color())
                    ->tooltip(fn (User $record): ?string => $record->access_reason),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('None'),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->state(fn (User $record): int => $record->getAllPermissions()->count()),
                TextColumn::make('employee.reportsTo.first_name')
                    ->label('Manager')
                    ->state(fn (User $record): ?string => $record->employee?->reportsTo?->fullName())
                    ->placeholder('—'),
                TextColumn::make('mfa')
                    ->label('MFA')
                    ->badge()
                    ->state(fn (User $record): string => MfaStatus::of($record))
                    ->color(fn (string $state): string => MfaStatus::color($state)),
                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('active_sessions')->label('Sessions')->numeric(),
                TextColumn::make('pending_ai_actions')->label('Pending AI actions')->numeric(),
                TextColumn::make('open_handoffs')->label('Open handoffs')->numeric(),
                TextColumn::make('separation')
                    ->label('Separation')
                    ->state(fn (User $record): ?string => self::separationSummary($record))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('access_status')
                    ->label('Access')
                    ->options(collect(AccessState::cases())->mapWithKeys(fn (AccessState $state) => [$state->value => $state->label()])->all()),
                TernaryFilter::make('needs_attention')
                    ->label('Needs attention')
                    ->queries(
                        true: fn (Builder $query) => $query->where(fn (Builder $attention) => $attention
                            ->whereHas('employee', fn (Builder $employee) => $employee->withTrashed()->where(fn (Builder $state) => $state->whereNotNull('deleted_at')->orWhere('status', '!=', EmployeeStatus::Active->value)))
                            ->where('access_status', AccessState::Active->value)
                            ->orWhereExists(fn ($handoff) => $handoff->from('ownership_handoffs')->whereColumn('ownership_handoffs.user_id', 'users.id')->where('status', OwnershipHandoff::OPEN))),
                        false: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ...UserAccessActions::all(),
                self::completeHandoffAction(),
            ])
            ->recordUrl(null);
    }

    /**
     * @return Builder<User>
     */
    public static function reviewQuery(): Builder
    {
        $viewer = Filament::auth()->user();
        $visible = $viewer instanceof User ? app(HierarchyService::class)->visibleEmployeeIdsFor($viewer) : collect();

        // SaaS-1: only the current tenant's members — "view all" is all of this tenant.
        return User::query()
            ->membersOfCurrentTenant()
            ->select('users.*')
            ->addSelect([
                'active_sessions' => DB::table('sessions')->selectRaw('count(*)')->whereColumn('sessions.user_id', 'users.id'),
                'pending_ai_actions' => AiToolCall::query()->selectRaw('count(*)')->whereColumn('ai_tool_calls.requested_by', 'users.id')->where('status', AiToolCallStatus::Pending->value),
                'open_handoffs' => OwnershipHandoff::query()->selectRaw('count(*)')->whereColumn('ownership_handoffs.user_id', 'users.id')->where('status', OwnershipHandoff::OPEN),
            ])
            ->with([
                'employee' => fn ($query) => $query->withTrashed()->with(['reportsTo', 'separations']),
                'roles.permissions',
                'permissions',
            ])
            ->when($visible !== null, fn (Builder $query) => $query->whereIn('users.employee_id', $visible));
    }

    private static function separationSummary(User $user): ?string
    {
        /** @var EmployeeSeparation|null $separation */
        $separation = $user->employee?->separations->whereNull('cancelled_at')->sortByDesc('id')->first();

        if ($separation === null) {
            return null;
        }

        $state = $separation->effective_applied_at !== null ? 'effective' : 'scheduled';

        return "Last day {$separation->separation_date->format('d M Y')} ({$state})";
    }

    private static function completeHandoffAction(): Action
    {
        return Action::make('completeHandoff')
            ->label('Mark handoff complete')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Allowed once no open application, requisition, interview or direct report is still attributed to this person.')
            ->visible(fn (User $record): bool => (int) $record->getAttribute('open_handoffs') > 0 && (bool) Filament::auth()->user()?->can('users.access.manage'))
            ->action(function (User $record): void {
                $actor = Filament::auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    OwnershipHandoff::query()->where('user_id', $record->id)->where('status', OwnershipHandoff::OPEN)->get()
                        ->each(fn (OwnershipHandoff $handoff) => app(OwnershipHandoffService::class)->complete($handoff, $actor));
                } catch (DomainException $e) {
                    Notification::make()->title('Handoff not complete')->body($e->getMessage())->danger()->persistent()->send();

                    throw new Halt;
                }

                Notification::make()->title('Handoff complete')->success()->send();
            });
    }
}
