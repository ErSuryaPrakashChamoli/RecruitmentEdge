<?php

use App\Jobs\AI\IndexAiDocumentJob;
use App\Jobs\AI\ReindexKnowledgeArticleJob;
use App\Jobs\GenerateRoleDnaSuggestionsJob;
use App\Jobs\SummarizeHiringMemoryJob;
use App\Models\CandidateApplication;
use App\Models\HiringMemoryRecord;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\IntelligenceAiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

test('AI jobs serialize ids only, and every unique AI job lets its lock expire', function (object $job): void {
    $payload = serialize($job);

    expect($payload)->not->toContain('full_name')->not->toContain('email')->not->toContain('App\\Models\\')
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(3600);
})->with([
    'role dna' => fn () => new GenerateRoleDnaSuggestionsJob(1, 2),
    'memory summary' => fn () => new SummarizeHiringMemoryJob(1, 2),
    'document index' => fn () => new IndexAiDocumentJob(1),
    'article reindex' => fn () => new ReindexKnowledgeArticleJob(1),
]);

test('provider failures are logged with status and error code, never the response body', function (string $provider): void {
    Log::spy();
    Http::fake(['*' => Http::response(['error' => ['status' => 'INTERNAL', 'code' => 500, 'message' => 'echo of PRIVATE-ALICE@example.invalid']], 500)]);
    $instance = $provider === 'gemini'
        ? new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta')
        : new OpenAiProvider('fake-key', 'https://api.openai.com/v1');

    expect(fn () => $instance->embed(['policy text']))->toThrow(AiProviderUnavailableException::class);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['status'] === 500
        && ! str_contains((string) json_encode($context), 'PRIVATE'))->once();
})->with(['gemini', 'openai']);

test('a Hiring Memory summary prompt carries only allowlisted facts', function (): void {
    $record = app(HiringMemoryService::class)->captureRejection(CandidateApplication::factory()->create());
    DB::table('hiring_memory_records')->where('id', $record->id)->update(['facts' => json_encode([...$record->facts, 'remarks' => 'PRIVATE-REMARK', 'future_fact' => 'PRIVATE-FUTURE', 'application_code' => 'APP-2026-999999'])]);
    $provider = new ScriptedLlmProvider([ScriptedLlmProvider::text('Summary.')]);
    app()->instance(LLMProviderInterface::class, $provider);

    app(IntelligenceAiService::class)->summarizeMemory(HiringMemoryRecord::query()->find($record->id));

    $prompt = (string) json_encode($provider->calls[0]['messages'][1]->content);

    expect($prompt)->toContain('days_in_process')
        ->not->toContain('PRIVATE-REMARK')
        ->not->toContain('PRIVATE-FUTURE')
        ->not->toContain('APP-2026-999999');
});
