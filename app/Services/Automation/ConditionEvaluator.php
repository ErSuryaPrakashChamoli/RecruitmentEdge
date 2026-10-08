<?php

namespace App\Services\Automation;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Evaluates a rule's condition tree against an AutomationContext (Phase 6).
 *
 * Tree shape: {match: all|any, negate: bool, rules: [leaf | group]} — at most MAX_DEPTH levels.
 * Leaf shape: {field, operator, value, amount, unit}. Every field comes from
 * AutomationFieldRegistry and every operator from its fixed per-type list, so evaluation is pure
 * data comparison: no expression language, no user code. Times are compared in the application
 * timezone. Returns a flat, human-readable trace of every leaf for the execution history.
 */
class ConditionEvaluator
{
    public const int MAX_DEPTH = 3;

    public function __construct(private readonly AutomationFieldRegistry $fields) {}

    /**
     * @param  array<string, mixed>|null  $tree
     * @return array{passed: bool, results: array<int, array{field: string, label: string, operator: string, expected: string, actual: string, passed: bool, depth: int}>}
     */
    public function evaluate(?array $tree, AutomationContext $context): array
    {
        $results = [];

        $passed = empty($tree['rules'] ?? []) ? true : $this->evaluateGroup($tree, $context, $results, 0);

        return ['passed' => $passed, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<int, array<string, mixed>>  $results
     */
    private function evaluateGroup(array $group, AutomationContext $context, array &$results, int $depth): bool
    {
        if ($depth >= self::MAX_DEPTH) {
            $results[] = $this->trace('(group)', 'Nested group', '', '', 'Too deeply nested — not evaluated', false, $depth);

            return false;
        }

        $matchAny = ($group['match'] ?? 'all') === 'any';
        $outcomes = [];

        foreach ($group['rules'] ?? [] as $rule) {
            $outcomes[] = isset($rule['rules'])
                ? $this->evaluateGroup($rule, $context, $results, $depth + 1)
                : $this->evaluateLeaf($rule, $context, $results, $depth);
        }

        $passed = $outcomes === [] || ($matchAny ? in_array(true, $outcomes, true) : ! in_array(false, $outcomes, true));

        return (bool) ($group['negate'] ?? false) ? ! $passed : $passed;
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<int, array<string, mixed>>  $results
     */
    private function evaluateLeaf(array $leaf, AutomationContext $context, array &$results, int $depth): bool
    {
        $field = $this->fields->find((string) ($leaf['field'] ?? ''));
        $operator = (string) ($leaf['operator'] ?? '');

        if ($field === null || ! array_key_exists($operator, AutomationFieldRegistry::OPERATORS[$field->type])) {
            $results[] = $this->trace((string) ($leaf['field'] ?? ''), 'Unknown condition', $operator, '', 'Invalid condition — treated as not met', false, $depth);

            return false;
        }

        try {
            $actual = $this->fields->resolve($field, $context);
            $passed = $this->compare($field->type, $operator, $actual, $leaf);
        } catch (Throwable $e) {
            report($e);
            $actual = null;
            $passed = false;
        }

        $results[] = $this->trace(
            $field->key,
            "{$field->group}: {$field->label}",
            AutomationFieldRegistry::OPERATORS[$field->type][$operator],
            $this->describeExpected($field->type, $operator, $leaf, $field->options()),
            $this->describeValue($actual, $field->options()),
            $passed,
            $depth,
        );

        return $passed;
    }

    /**
     * @param  array<string, mixed>  $leaf
     */
    private function compare(string $type, string $operator, mixed $actual, array $leaf): bool
    {
        $expected = $leaf['value'] ?? null;
        $isSet = $actual !== null && $actual !== '';

        if ($operator === 'exists') {
            return $isSet;
        }

        if ($operator === 'not_exists') {
            return ! $isSet;
        }

        if ($type === 'boolean') {
            return (bool) $actual === filter_var($expected, FILTER_VALIDATE_BOOLEAN);
        }

        if ($type === 'datetime') {
            return $isSet && $this->compareDate($operator, Carbon::parse($actual), $leaf);
        }

        if ($type === 'number') {
            if (! $isSet || ! is_numeric($actual) || ! is_numeric($expected)) {
                return false;
            }

            $actual = (float) $actual;
            $expected = (float) $expected;

            return match ($operator) {
                'equals' => $actual === $expected,
                'not_equals' => $actual !== $expected,
                'greater_than' => $actual > $expected,
                'greater_than_or_equal' => $actual >= $expected,
                'less_than' => $actual < $expected,
                'less_than_or_equal' => $actual <= $expected,
                default => false,
            };
        }

        $actualText = Str::lower((string) $actual);
        $expectedList = array_map(fn ($value) => Str::lower((string) $value), (array) $expected);

        return match ($operator) {
            'equals' => $isSet && $actualText === ($expectedList[0] ?? null),
            'not_equals' => $actualText !== ($expectedList[0] ?? null),
            'in' => $isSet && in_array($actualText, $expectedList, true),
            'not_in' => ! in_array($actualText, $expectedList, true),
            'contains' => $isSet && ($expectedList[0] ?? '') !== '' && str_contains($actualText, $expectedList[0]),
            'not_contains' => ($expectedList[0] ?? '') === '' || ! str_contains($actualText, $expectedList[0]),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $leaf
     */
    private function compareDate(string $operator, CarbonInterface $actual, array $leaf): bool
    {
        if (in_array($operator, AutomationFieldRegistry::DURATION_OPERATORS, true)) {
            $amount = (int) ($leaf['amount'] ?? 0);
            $unit = (string) ($leaf['unit'] ?? 'hours');

            return match ($operator) {
                'within' => $actual->between(now(), AutomationTime::ahead($amount, $unit)),
                'older_than' => $actual->lte(AutomationTime::ago($amount, $unit)),
                'newer_than' => $actual->gt(AutomationTime::ago($amount, $unit)),
            };
        }

        if (blank($leaf['value'] ?? null)) {
            return false;
        }

        $expected = Carbon::parse($leaf['value']);

        return $operator === 'before' ? $actual->lt($expected) : $actual->gt($expected);
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @param  array<string, string>  $options
     */
    private function describeExpected(string $type, string $operator, array $leaf, array $options): string
    {
        if (in_array($operator, AutomationFieldRegistry::UNARY_OPERATORS, true)) {
            return '';
        }

        if (in_array($operator, AutomationFieldRegistry::DURATION_OPERATORS, true)) {
            return AutomationTime::describe((int) ($leaf['amount'] ?? 0), (string) ($leaf['unit'] ?? 'hours'));
        }

        if ($type === 'boolean') {
            return filter_var($leaf['value'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
        }

        return collect((array) ($leaf['value'] ?? []))->map(fn ($value) => $options[(string) $value] ?? (string) $value)->implode(', ');
    }

    /**
     * @param  array<string, string>  $options
     */
    private function describeValue(mixed $value, array $options): string
    {
        return match (true) {
            $value === null || $value === '' => '(not set)',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof CarbonInterface => $value->toDayDateTimeString(),
            default => $options[(string) $value] ?? (string) $value,
        };
    }

    /**
     * @return array{field: string, label: string, operator: string, expected: string, actual: string, passed: bool, depth: int}
     */
    private function trace(string $field, string $label, string $operator, string $expected, string $actual, bool $passed, int $depth): array
    {
        return compact('field', 'label', 'operator', 'expected', 'actual', 'passed', 'depth');
    }
}
