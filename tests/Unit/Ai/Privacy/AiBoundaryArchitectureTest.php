<?php

/**
 * Phase 8.1 architecture guard: the AI privacy boundary must not depend on developers remembering
 * to call AiProjector. These rules fail CI when AI code serializes whole records, reads contact /
 * pay / private fields for output, or talks to a provider without going through AiGateway.
 */
arch('only the AI gateway (and the provider wiring) may use concrete AI providers')
    ->expect('App\Services\AI\Providers')
    ->toOnlyBeUsedIn([
        'App\Services\AI\Providers',
        'App\Services\AI\Gateway',
        'App\Providers\AiServiceProvider',
        // Diagnostic only: sends fixed, hard-coded prompts to a named provider, never application data.
        'App\Console\Commands\AiTestProviderCommand',
    ]);

arch('AI tools never call external services directly')
    ->expect('App\Services\AI\Tools')
    ->not->toUse(['Illuminate\Support\Facades\Http', 'App\Services\AI\Providers', 'App\Services\AI\Contracts\LLMProviderInterface', 'App\Services\AI\Contracts\EmbeddingProviderInterface', 'App\Services\AI\Contracts\WebSearchProviderInterface']);

/**
 * @return array<string, string> relative path => source, for files that build provider-bound data
 */
function aiBoundarySources(): array
{
    $root = dirname(__DIR__, 4);
    $files = [
        ...glob($root.'/app/Services/AI/Tools/*/*.php'),
        ...glob($root.'/app/Services/AI/Orchestrator/*.php'),
        $root.'/app/Services/Intelligence/IntelligenceAiService.php',
        $root.'/app/Services/RecruitmentInsightsService.php',
    ];

    return collect($files)->mapWithKeys(fn (string $file) => [str_replace($root.'/', '', $file) => (string) file_get_contents($file)])->all();
}

test('AI code never dumps whole records into provider-bound data', function (): void {
    $pattern = '/->(getAttributes|getRelations|attributesToArray|relationsToArray)\(|->relations\b|\$(candidate|application|requisition|employee|recruiter|offer|interview|joining|followup|risk|record|model)\w*->toArray\(\)/';

    foreach (aiBoundarySources() as $path => $source) {
        expect(preg_match($pattern, $source, $match))->toBe(0, "{$path} serializes a model: ".($match[0] ?? ''));
    }
});

test('AI code only reads contact, pay and private fields where they never reach the output', function (): void {
    $pattern = '/->(email|mobile|alternate_mobile|current_salary|expected_salary|offered_ctc|fixed_salary|variable_salary|joining_bonus|salary_min|salary_max|remarks|full_name|current_company|resume_path|meeting_link)\b/';
    // file => the only reason it may read one of these fields
    $allowed = [
        'app/Services/AI/Tools/ActionTools/DraftCandidateEmailTool.php' => 'blank($candidate->email)',
        'app/Services/AI/Tools/ActionTools/SendCandidateEmailTool.php' => 'blank($candidate->email)',
    ];

    foreach (aiBoundarySources() as $path => $source) {
        $source = isset($allowed[$path]) ? str_replace($allowed[$path], '', $source) : $source;

        expect(preg_match($pattern, $source, $match))->toBe(0, "{$path} reads a prohibited field for AI output: ".($match[0] ?? ''));
    }
});

test('every AI tool that describes records builds them through AiProjector', function (): void {
    // Tools that read people records but return only ids, counts or rates (verified end to end by
    // ToolPayloadContractTest). A new entry here needs the same justification.
    $idsOrAggregatesOnly = [
        'app/Services/AI/Tools/ActionTools/CreateFollowupTool.php',
        'app/Services/AI/Tools/ActionTools/MoveCandidatesStageTool.php',
        'app/Services/AI/Tools/ActionTools/RejectCandidatesTool.php',
        'app/Services/AI/Tools/JobTools/GetRequisitionPipelineTool.php',
        'app/Services/AI/Tools/OfferTools/AnalyzeJoiningConversionTool.php',
        'app/Services/AI/Tools/OfferTools/AnalyzeOffersTool.php',
    ];

    foreach (aiBoundarySources() as $path => $source) {
        if (! str_contains($path, '/Tools/') || str_contains($path, '/Concerns/') || str_contains($path, '/Contracts/') || in_array($path, $idsOrAggregatesOnly, true)) {
            continue;
        }

        $touchesPeople = preg_match('/\b(Candidate|CandidateApplication|Employee|Interview|Offer|CandidateJoining|RecruitmentFollowup|HiringRisk)::query\(\)|->candidate\b|->recruiter\b|->interviewer\b/', $source) === 1;

        if ($touchesPeople) {
            expect(str_contains($source, 'ProjectsForAi'))->toBeTrue("{$path} reads people records without AiProjector");
        }
    }
});
