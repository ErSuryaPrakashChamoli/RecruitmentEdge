<?php

namespace App\Filament\Resources\AutomationRules\Schemas;

use App\Models\AutomationRule;
use App\Services\Automation\AutomationFieldRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Translates between the stored rule configuration (condition tree, action list, escalation) and
 * the visual builder's form state. The builder never exposes raw JSON: a condition row stores its
 * value in a type-specific field (choice / flag / value / values / date / amount+unit) and actions
 * are Builder blocks.
 */
final class AutomationRuleFormData
{
    /**
     * @return array<string, mixed>
     */
    public static function toForm(AutomationRule $rule): array
    {
        $conditions = $rule->conditions ?? ['match' => 'all', 'rules' => []];
        $leaves = collect($conditions['rules'] ?? [])->reject(fn (array $rule) => isset($rule['rules']));
        $groups = collect($conditions['rules'] ?? [])->filter(fn (array $rule) => isset($rule['rules']));
        $stop = $rule->escalation['stop_conditions'] ?? ['match' => 'any', 'rules' => []];

        return [
            ...$rule->only(['name', 'description', 'priority', 'trigger', 'scope_id', 'cooldown_minutes', 'max_executions_per_day', 'max_executions_per_entity', 'effective_from', 'effective_until']),
            'scope_type' => $rule->scope_type->value,
            'failure_behavior' => $rule->failure_behavior->value,
            'timing' => ['mode' => 'immediate', 'unit' => 'hours', 'direction' => 'after', 'anchor' => 'event', ...($rule->timing ?? [])],
            'condition_match' => $conditions['match'] ?? 'all',
            'condition_negate' => (bool) ($conditions['negate'] ?? false),
            'condition_rules' => self::keyed($leaves->map(fn (array $leaf) => self::leafToForm($leaf))),
            'condition_groups' => self::keyed($groups->map(fn (array $group) => [
                'match' => $group['match'] ?? 'all',
                'negate' => (bool) ($group['negate'] ?? false),
                'rules' => self::keyed(collect($group['rules'])->reject(fn (array $rule) => isset($rule['rules']))->map(fn (array $leaf) => self::leafToForm($leaf))),
            ])),
            'actions_builder' => self::keyed(collect($rule->actions ?? [])->map(fn (array $action) => [
                'type' => $action['type'],
                'data' => collect($action)->except('type')->all(),
            ])),
            'escalation_steps' => self::keyed(collect($rule->escalation['steps'] ?? [])),
            'stop_match' => $stop['match'] ?? 'any',
            'stop_rules' => self::keyed(collect($stop['rules'] ?? [])->reject(fn (array $rule) => isset($rule['rules']))->map(fn (array $leaf) => self::leafToForm($leaf))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(array $data): array
    {
        $groups = collect($data['condition_groups'] ?? [])->values()->map(fn (array $group) => [
            'match' => $group['match'] ?? 'all',
            'negate' => (bool) ($group['negate'] ?? false),
            'rules' => collect($group['rules'] ?? [])->values()->map(fn (array $leaf) => self::leafFromForm($leaf))->all(),
        ])->filter(fn (array $group) => $group['rules'] !== []);

        $stopRules = collect($data['stop_rules'] ?? [])->values()->map(fn (array $leaf) => self::leafFromForm($leaf))->all();

        return [
            ...collect($data)->only(['name', 'description', 'priority', 'trigger', 'scope_type', 'scope_id', 'failure_behavior', 'cooldown_minutes', 'max_executions_per_day', 'max_executions_per_entity', 'effective_from', 'effective_until'])->all(),
            'timing' => array_filter($data['timing'] ?? [], fn ($value) => $value !== null && $value !== ''),
            'conditions' => [
                'match' => $data['condition_match'] ?? 'all',
                'negate' => (bool) ($data['condition_negate'] ?? false),
                'rules' => [...collect($data['condition_rules'] ?? [])->values()->map(fn (array $leaf) => self::leafFromForm($leaf))->all(), ...$groups->all()],
            ],
            'actions' => collect($data['actions_builder'] ?? [])->values()->map(fn (array $block) => ['type' => $block['type'], ...array_filter($block['data'] ?? [], fn ($value) => $value !== null && $value !== '' && $value !== [])])->all(),
            'escalation' => [
                'steps' => collect($data['escalation_steps'] ?? [])->values()->map(fn (array $step) => array_filter($step, fn ($value) => $value !== null && $value !== ''))->all(),
                'stop_conditions' => $stopRules === [] ? null : ['match' => $data['stop_match'] ?? 'any', 'negate' => false, 'rules' => $stopRules],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @return array<string, mixed>
     */
    public static function leafFromForm(array $leaf): array
    {
        $field = app(AutomationFieldRegistry::class)->find((string) ($leaf['field'] ?? ''));
        $operator = (string) ($leaf['operator'] ?? '');
        $base = ['field' => $leaf['field'] ?? null, 'operator' => $operator];

        return match (true) {
            in_array($operator, AutomationFieldRegistry::UNARY_OPERATORS, true) => $base,
            in_array($operator, AutomationFieldRegistry::DURATION_OPERATORS, true) => [...$base, 'amount' => (int) ($leaf['amount'] ?? 0), 'unit' => $leaf['unit'] ?? 'hours'],
            in_array($operator, ['before', 'after'], true) => [...$base, 'value' => $leaf['date'] ?? null],
            in_array($operator, ['in', 'not_in'], true) => [...$base, 'value' => array_values((array) ($leaf['values'] ?? []))],
            $field?->type === 'boolean' => [...$base, 'value' => (string) ($leaf['flag'] ?? '1')],
            $field?->type === 'enum' => [...$base, 'value' => $leaf['choice'] ?? null],
            default => [...$base, 'value' => $leaf['value'] ?? null],
        };
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @return array<string, mixed>
     */
    public static function leafToForm(array $leaf): array
    {
        $field = app(AutomationFieldRegistry::class)->find((string) ($leaf['field'] ?? ''));
        $operator = (string) ($leaf['operator'] ?? '');
        $value = $leaf['value'] ?? null;

        return [
            'field' => $leaf['field'] ?? null,
            'operator' => $operator,
            'amount' => $leaf['amount'] ?? null,
            'unit' => $leaf['unit'] ?? 'hours',
            'date' => in_array($operator, ['before', 'after'], true) ? $value : null,
            'values' => in_array($operator, ['in', 'not_in'], true) ? (array) $value : [],
            'flag' => $field?->type === 'boolean' ? (string) $value : null,
            'choice' => $field?->type === 'enum' && ! is_array($value) ? $value : null,
            'value' => in_array($field?->type, ['string', 'number'], true) && ! is_array($value) ? $value : null,
        ];
    }

    /**
     * Repeater/Builder state is keyed by item UUID.
     *
     * @param  Collection<int, mixed>  $items
     * @return array<string, mixed>
     */
    private static function keyed(Collection $items): array
    {
        return $items->values()->mapWithKeys(fn ($item) => [(string) Str::uuid() => $item])->all();
    }
}
