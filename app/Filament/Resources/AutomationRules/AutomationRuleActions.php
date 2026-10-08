<?php

namespace App\Filament\Resources\AutomationRules;

use App\Enums\AutomationRuleStatus;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationRuleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Lifecycle actions shared by the rules table and the rule pages. AutomationRuleService validates
 * and audits; a rule that cannot be activated says exactly why (including the Phase 8.6
 * separation-of-duties rule). Each needs a reason.
 */
class AutomationRuleActions
{
    use GuardsDomainExceptions;

    public static function activate(): Action
    {
        return Action::make('activate')
            ->icon('heroicon-o-play')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The rule is validated first (conditions, actions, templates, providers and your permission for its scope). Try a dry run before activating an important rule.')
            ->visible(fn (AutomationRule $record) => in_array($record->status, [AutomationRuleStatus::Draft, AutomationRuleStatus::Paused], true) && (auth()->user()?->can('activate', $record) ?? false))
            ->schema([self::reasonField()])
            ->action(function (AutomationRule $record, array $data): void {
                self::guarded('The rule cannot be activated', fn () => app(AutomationRuleService::class)->activate($record, auth()->user(), $data['reason'] ?? null));
                Notification::make()->title('Rule activated')->success()->send();
            });
    }

    public static function pause(): Action
    {
        return Action::make('pause')
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (AutomationRule $record) => $record->isActive() && (auth()->user()?->can('activate', $record) ?? false))
            ->schema([self::reasonField()])
            ->action(function (AutomationRule $record, array $data): void {
                self::guarded('The rule could not be paused', fn () => app(AutomationRuleService::class)->pause($record, auth()->user(), $data['reason'] ?? null));
                Notification::make()->title('Rule paused')->success()->send();
            });
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Archived rules stop running, their pending runs and escalations are cancelled, and they can no longer be edited. History is kept.')
            ->visible(fn (AutomationRule $record) => $record->status !== AutomationRuleStatus::Archived && (auth()->user()?->can('activate', $record) ?? false))
            ->schema([self::reasonField()])
            ->action(function (AutomationRule $record, array $data): void {
                self::guarded('The rule could not be archived', fn () => app(AutomationRuleService::class)->archive($record, auth()->user(), $data['reason'] ?? null));
                Notification::make()->title('Rule archived')->success()->send();
            });
    }

    /**
     * Phase 8.6 (D8.6-021): every lifecycle change of a rule records why.
     */
    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('Reason')->required()->maxLength(1000);
    }

    public static function duplicate(): Action
    {
        return Action::make('duplicate')
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn () => auth()->user()?->can('automation.manage') ?? false)
            ->action(function (AutomationRule $record): void {
                $copy = self::guarded('The rule could not be duplicated', fn () => app(AutomationRuleService::class)->duplicate($record, auth()->user()));
                Notification::make()->title('Draft copy created')->success()->send();
                redirect(AutomationRuleResource::getUrl('edit', ['record' => $copy]));
            });
    }

    public static function dryRun(): Action
    {
        return Action::make('dryRun')
            ->label('Dry run')
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->url(fn (AutomationRule $record) => AutomationRuleResource::getUrl('dry-run', ['record' => $record]));
    }
}
