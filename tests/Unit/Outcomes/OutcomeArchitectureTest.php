<?php

use App\Enums\OutcomeType;

/**
 * Phase 8.2 architecture guard for the Outcome Loop: outcomes are deterministic application logic,
 * written by one service, never inferred from audit history, never computed by AI, and never built
 * from personal or protected attributes.
 */
arch('outcome services never talk to an AI provider or the gateway directly')
    ->expect('App\Services\Outcomes')
    ->not->toUse(['App\Services\AI\Gateway\AiGateway', 'App\Services\AI\Providers', 'App\Services\AI\Contracts\LLMProviderInterface', 'Illuminate\Support\Facades\Http']);

/**
 * @return array<string, string> relative path => source
 */
function outcomeArchitectureSources(string ...$globs): array
{
    $root = dirname(__DIR__, 3);

    return collect($globs)
        ->flatMap(fn (string $glob) => glob($root.'/'.$glob) ?: [])
        ->mapWithKeys(fn (string $file) => [str_replace($root.'/', '', $file) => (string) file_get_contents($file)])
        ->all();
}

test('only OutcomeService creates or changes outcome records', function (): void {
    $sources = outcomeArchitectureSources('app/*.php', 'app/*/*.php', 'app/*/*/*.php', 'app/*/*/*/*.php', 'app/*/*/*/*/*.php');

    foreach ($sources as $path => $source) {
        if ($path === 'app/Services/Outcomes/OutcomeService.php') {
            continue;
        }

        expect(preg_match('/HiringOutcome::(query\(\)->)?(create|forceCreate|insert|updateOrCreate|firstOrCreate)\(|hiring_outcomes[\'"]\)->(insert|update)/', $source, $match))->toBe(0, "{$path} writes outcomes outside OutcomeService: ".($match[0] ?? ''));
    }
});

test('outcome code never reads the audit log as evidence', function (): void {
    foreach (outcomeArchitectureSources('app/Services/Outcomes/*.php') as $path => $source) {
        expect(preg_match('/AuditLog::(query|where|latest|first|find|all)\b|[\'"]audit_logs[\'"]/', $source, $match))->toBe(0, "{$path} reads the audit log: ".($match[0] ?? ''));
    }
});

test('outcome code never reads personal, pay or protected attributes', function (): void {
    $pattern = '/->(email|mobile|alternate_mobile|full_name|first_name|last_name|date_of_birth|dob|gender|marital_status|religion|caste|nationality|photo\w*|current_salary|expected_salary|offered_ctc|fixed_salary|remarks)\b/';

    foreach (outcomeArchitectureSources('app/Services/Outcomes/*.php') as $path => $source) {
        expect(preg_match($pattern, $source, $match))->toBe(0, "{$path} reads a personal or protected field: ".($match[0] ?? ''));
    }
});

test('the taxonomy has no outcome type without source data', function (): void {
    $values = collect(OutcomeType::cases())->map->value->implode(' ');

    expect(preg_match('/perform|attend|probation|promot|retention|attrition/', $values))->toBe(0)
        ->and(array_keys(OutcomeType::UNAVAILABLE))->toBe(['performance', 'attendance', 'probation', 'promotion', 'historical_retention']);
});

test('Phase 8.2 migrations are additive', function (): void {
    $root = dirname(__DIR__, 3);
    $migrations = collect(glob($root.'/database/migrations/2026_09_26_*.php'))
        ->filter(fn (string $file) => preg_match('/(outcome|separation|phase_eight_two)/', $file) === 1);

    expect($migrations)->not->toBeEmpty();

    foreach ($migrations as $file) {
        $source = (string) file_get_contents($file);
        $up = substr($source, strpos($source, 'function up()'), strpos($source, 'function down()') - strpos($source, 'function up()'));

        expect(preg_match('/dropColumn|dropIfExists|Schema::drop|renameColumn|->change\(\)|->delete\(|truncate/', $up, $match))->toBe(0, basename($file).' is destructive: '.($match[0] ?? ''));
    }
});
