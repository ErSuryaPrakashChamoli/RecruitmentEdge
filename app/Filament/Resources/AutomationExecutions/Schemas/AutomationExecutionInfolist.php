<?php

namespace App\Filament\Resources\AutomationExecutions\Schemas;

use App\Enums\AutomationActionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\EscalationStatus;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Models\AutomationExecution;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Automation\AutomationLinks;
use App\Services\Automation\RecipientResolver;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * "Why did this happen?" — the full story of one automation run.
 */
class AutomationExecutionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('What happened')->columns(4)->schema([
                TextEntry::make('rule.name')->label('Rule')->url(fn (AutomationExecution $record) => $record->rule !== null ? AutomationRuleResource::getUrl('view', ['record' => $record->rule]) : null),
                TextEntry::make('ruleVersion.version')->label('Rule version')->prefix('v')->placeholder('—'),
                TextEntry::make('trigger')->formatStateUsing(fn (string $state) => app(AutomationEventRegistry::class)->find($state)?->label ?? $state),
                TextEntry::make('status')->badge()->formatStateUsing(fn (AutomationExecutionStatus $state) => $state->label())->color(fn (AutomationExecutionStatus $state) => $state->color()),
                TextEntry::make('record')
                    ->state(fn (AutomationExecution $record) => trim(class_basename($record->subject_type).' #'.$record->subject_id.' '.($record->candidateApplication?->candidate?->full_name ?? '')))
                    ->url(fn (AutomationExecution $record) => AutomationLinks::for($record->subject)),
                TextEntry::make('triggered_at')->dateTime(),
                TextEntry::make('started_at')->dateTime()->placeholder('Not started'),
                TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                TextEntry::make('scheduled_for')->label('Scheduled for')->dateTime(),
                TextEntry::make('retry_count')->label('Retries'),
                TextEntry::make('depth')->label('Chain depth')->helperText('0 = started by a person or the system; higher = started by another automation.'),
                TextEntry::make('parent.rule.name')->label('Started by rule')->placeholder('—'),
                TextEntry::make('skip_reason')->label('Why it was skipped')->placeholder('—')->columnSpanFull(),
                TextEntry::make('failure_reason')->label('What failed')->color('danger')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Conditions')->schema([
                RepeatableEntry::make('condition_results')->hiddenLabel()->placeholder('No conditions evaluated (none configured, or the run stopped earlier).')->columns(5)->schema([
                    TextEntry::make('passed')->hiddenLabel()->formatStateUsing(fn ($state) => $state ? '✓ met' : '✗ not met')->color(fn ($state) => $state ? 'success' : 'danger'),
                    TextEntry::make('label')->hiddenLabel(),
                    TextEntry::make('operator')->hiddenLabel(),
                    TextEntry::make('expected')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('actual')->hiddenLabel()->prefix('actual: '),
                ]),
            ]),
            Section::make('Actions')->schema([
                RepeatableEntry::make('actionExecutions')->hiddenLabel()->placeholder('No actions ran.')->columns(4)->schema([
                    TextEntry::make('action_type')->hiddenLabel()->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state))),
                    TextEntry::make('status')->hiddenLabel()->badge()->formatStateUsing(fn (AutomationActionStatus $state) => $state->label())->color(fn (AutomationActionStatus $state) => $state->color()),
                    TextEntry::make('summary')->hiddenLabel()->placeholder('—'),
                    TextEntry::make('error')->hiddenLabel()->color('danger')->placeholder('—'),
                ]),
            ]),
            Section::make('Escalations')->visible(fn (AutomationExecution $record) => $record->escalations()->exists())->schema([
                RepeatableEntry::make('escalations')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('step')->hiddenLabel()->prefix('Step '),
                    TextEntry::make('target')->hiddenLabel()->formatStateUsing(fn (string $state) => RecipientResolver::label($state)),
                    TextEntry::make('status')->hiddenLabel()->badge()->formatStateUsing(fn (EscalationStatus $state) => $state->label())->color(fn (EscalationStatus $state) => $state->color()),
                    TextEntry::make('outcome')->hiddenLabel()->placeholder('Waiting until due'),
                ]),
            ]),
        ]);
    }
}
