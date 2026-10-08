<?php

namespace App\Filament\Resources\AutomationRules\Schemas;

use App\Enums\AutomationRuleStatus;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationActionRegistry;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Automation\AutomationFieldRegistry;
use App\Services\Automation\AutomationHealthService;
use App\Services\Automation\AutomationScopeResolver;
use App\Services\Automation\AutomationTemplateCatalog;
use App\Services\Automation\AutomationTime;
use App\Services\Automation\RecipientResolver;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A rule read back in plain language: WHEN … IF … THEN … ESCALATE …, plus its health.
 */
class AutomationRuleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Summary')->columns(4)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn (AutomationRuleStatus $state) => $state->label())->color(fn (AutomationRuleStatus $state) => $state->color()),
                TextEntry::make('version')->prefix('v'),
                TextEntry::make('scope')->state(fn (AutomationRule $record) => app(AutomationScopeResolver::class)->describe($record)),
                TextEntry::make('health')
                    ->badge()
                    ->state(fn (AutomationRule $record) => $record->isActive() ? app(AutomationHealthService::class)->forRule($record)['status'] : 'not running')
                    ->color(fn (string $state) => match ($state) {
                        AutomationHealthService::HEALTHY => 'success',
                        AutomationHealthService::WARNING => 'warning',
                        AutomationHealthService::FAILED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextEntry::make('health_signals')
                    ->label('Health signals')
                    ->state(fn (AutomationRule $record) => $record->isActive() ? collect(app(AutomationHealthService::class)->forRule($record)['signals'])->pluck('message')->all() : [])
                    ->listWithLineBreaks()
                    ->placeholder('None')
                    ->columnSpanFull(),
                TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                TextEntry::make('replaces')
                    ->label('Replaces built-in alert')
                    ->state(fn (AutomationRule $record) => $record->template_key !== null ? implode(', ', app(AutomationTemplateCatalog::class)->find($record->template_key)['replaces_alert'] ?? []) : null)
                    ->helperText('While this rule is active, that hourly alert is skipped so nobody is alerted twice.')
                    ->visible(fn (AutomationRule $record) => filled(app(AutomationTemplateCatalog::class)->find((string) $record->template_key)['replaces_alert'] ?? null))
                    ->columnSpanFull(),
            ]),
            Section::make('WHEN')->schema([
                TextEntry::make('when')->hiddenLabel()->state(fn (AutomationRule $record) => self::when($record)),
            ]),
            Section::make('IF')->schema([
                TextEntry::make('if')->hiddenLabel()->listWithLineBreaks()->state(fn (AutomationRule $record) => self::conditions($record->conditions) ?: ['Always (no conditions)']),
            ]),
            Section::make('THEN')->schema([
                TextEntry::make('then')->hiddenLabel()->listWithLineBreaks()->bulleted()->state(fn (AutomationRule $record) => collect($record->actions ?? [])
                    ->map(fn (array $action) => app(AutomationActionRegistry::class)->find((string) $action['type'])?->describe($action) ?? $action['type'])
                    ->all()),
                TextEntry::make('failure_behavior')->label('If an action fails')->formatStateUsing(fn ($state) => $state->label()),
            ]),
            Section::make('ESCALATE')->schema([
                TextEntry::make('escalate')->hiddenLabel()->listWithLineBreaks()->bulleted()->placeholder('No escalation')->state(fn (AutomationRule $record) => collect($record->escalation['steps'] ?? [])
                    ->map(fn (array $step) => 'After '.AutomationTime::describe((int) ($step['after'] ?? 0), (string) ($step['unit'] ?? 'hours')).' → '.RecipientResolver::label((string) ($step['target'] ?? '')).(($step['create_action'] ?? false) ? ' (with an action)' : ''))
                    ->all()),
                TextEntry::make('stop')->label('Stops when')->listWithLineBreaks()->placeholder('Its Action Center item is closed')->state(fn (AutomationRule $record) => self::conditions($record->escalation['stop_conditions'] ?? null)),
            ]),
        ]);
    }

    private static function when(AutomationRule $rule): string
    {
        $definition = app(AutomationEventRegistry::class)->find($rule->trigger);
        $timing = $rule->timing ?? [];

        if ($definition === null) {
            return $rule->trigger;
        }

        if ($definition->isScheduled()) {
            $sentence = "{$definition->label} — {$definition->thresholdLabel} ".AutomationTime::describe((int) ($timing['amount'] ?? 0), (string) ($timing['unit'] ?? 'hours'));

            return $sentence.(filled($timing['repeat_every_hours'] ?? null) ? ", repeating every {$timing['repeat_every_hours']} hours" : '');
        }

        if (($timing['mode'] ?? 'immediate') !== 'delay') {
            return "{$definition->label} — run immediately";
        }

        $anchor = $definition->anchors[$timing['anchor'] ?? 'event'] ?? 'the event';

        return "{$definition->label} — run ".AutomationTime::describe((int) ($timing['amount'] ?? 0), (string) ($timing['unit'] ?? 'hours')).' '.($timing['direction'] ?? 'after').' '.lcfirst($anchor);
    }

    /**
     * @param  array<string, mixed>|null  $tree
     * @return array<int, string>
     */
    public static function conditions(?array $tree, int $depth = 0): array
    {
        if ($tree === null || empty($tree['rules'] ?? [])) {
            return [];
        }

        $fields = app(AutomationFieldRegistry::class);
        $lines = [str_repeat('   ', $depth).(($tree['negate'] ?? false) ? 'NOT ' : '').(($tree['match'] ?? 'all') === 'any' ? 'ANY of:' : 'ALL of:')];

        foreach ($tree['rules'] as $rule) {
            if (isset($rule['rules'])) {
                $lines = [...$lines, ...self::conditions($rule, $depth + 1)];

                continue;
            }

            $field = $fields->find((string) ($rule['field'] ?? ''));
            $operator = $field !== null ? (AutomationFieldRegistry::OPERATORS[$field->type][$rule['operator']] ?? $rule['operator']) : ($rule['operator'] ?? '');
            $value = match (true) {
                in_array($rule['operator'] ?? '', AutomationFieldRegistry::DURATION_OPERATORS, true) => AutomationTime::describe((int) ($rule['amount'] ?? 0), (string) ($rule['unit'] ?? 'hours')),
                $field?->type === 'boolean' => filter_var($rule['value'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No',
                default => collect((array) ($rule['value'] ?? []))->map(fn ($v) => $field?->options()[(string) $v] ?? $v)->implode(', '),
            };

            $lines[] = str_repeat('   ', $depth + 1).'• '.($field !== null ? "{$field->group}: {$field->label}" : ($rule['field'] ?? '?'))." {$operator} {$value}";
        }

        return $lines;
    }
}
