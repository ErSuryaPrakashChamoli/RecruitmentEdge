<?php

namespace App\Console\Commands;

use App\Enums\AiRiskLevel;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\ToolDefinition;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Safe, read-only connectivity check for one configured provider (spec section 28). Never prints
 * the API key. Uses real network calls when a credential is present — do not add this to CI
 * without a live key; ProviderIndependenceTest.php covers the same request/response shapes with
 * Http::fake() for credential-free automated testing.
 */
#[Signature('ai:test-provider {provider=gemini : gemini or openai}')]
#[Description('Smoke-test a configured AI provider: config, credential, a minimal request, structured output, and tool calling')]
class AiTestProviderCommand extends Command
{
    public function handle(): int
    {
        $name = strtolower((string) $this->argument('provider'));

        $this->line("== AI provider smoke test: {$name} ==");

        // 1. Configuration present
        $config = config("ai.providers.{$name}");

        if ($config === null) {
            $this->error("Unknown provider [{$name}]. Expected one of: gemini, openai.");

            return self::FAILURE;
        }

        $this->info('1. Configuration block found.');

        // 2. Credential present
        if (blank($config['api_key'] ?? null)) {
            $this->warn("2. No credential set for [{$name}] (see config/ai.php / .env). Stopping here — this is expected, not an error.");

            return self::SUCCESS;
        }

        $this->info('2. Credential is present.');

        $llmProvider = match ($name) {
            'gemini' => app(GeminiProvider::class),
            'openai' => app(OpenAiProvider::class),
            default => null,
        };

        if ($llmProvider === null || ! $llmProvider->isConfigured()) {
            $this->error('Provider did not report itself as configured — check the credential value.');

            return self::FAILURE;
        }

        $model = config('ai.models.classification');

        // 3. Minimal request
        try {
            $response = $llmProvider->complete(
                [LlmMessage::user('Reply with exactly one word: pong')],
                [],
                $model,
            );
        } catch (Throwable $e) {
            $this->error('3. Minimal request FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("3. Minimal request succeeded. Model replied: \"{$response->content}\"");
        $this->line("   Usage: {$response->usage['input_tokens']} in / {$response->usage['output_tokens']} out / {$response->usage['cached_tokens']} cached");

        // 4. Structured output
        try {
            $structured = $llmProvider->structured(
                [LlmMessage::user('Return a JSON object describing a fictional candidate named Alex with an "experience_years" integer field.')],
                $model,
                ['name' => 'candidate', 'schema' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string'], 'experience_years' => ['type' => 'integer']],
                    'required' => ['name', 'experience_years'],
                ]],
            );
            $this->info('4. Structured output succeeded: '.json_encode($structured));
        } catch (Throwable $e) {
            $this->warn('4. Structured output failed (model/provider may not support it): '.$e->getMessage());
        }

        // 5. Tool calling
        try {
            $tools = [new ToolDefinition(
                name: 'get_weather',
                description: 'Get the current weather for a city',
                parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                riskLevel: AiRiskLevel::Read,
            )];

            $toolResponse = $llmProvider->complete(
                [LlmMessage::user('What is the weather in Delhi? Use the get_weather tool.')],
                $tools,
                $model,
            );

            if ($toolResponse->hasToolCalls()) {
                $this->info('5. Tool calling succeeded: model requested '.$toolResponse->toolCalls[0]['name'].'('.json_encode($toolResponse->toolCalls[0]['arguments']).')');
            } else {
                $this->warn('5. Model did not request the tool it was offered — check prompt/model tool-calling support.');
            }
        } catch (Throwable $e) {
            $this->warn('5. Tool calling failed: '.$e->getMessage());
        }

        $this->newLine();
        $this->info("Provider [{$name}] is reachable and returning normalized responses.");

        return self::SUCCESS;
    }
}
