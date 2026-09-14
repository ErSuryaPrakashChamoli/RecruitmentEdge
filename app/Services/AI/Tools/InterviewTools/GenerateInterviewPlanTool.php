<?php

namespace App\Services\AI\Tools\InterviewTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\Concerns\CallsLanguageModel;
use App\Services\AI\Tools\Contracts\AiTool;

class GenerateInterviewPlanTool implements AiTool
{
    use CallsLanguageModel;

    public function __construct(private readonly AiGateway $gateway) {}

    public function name(): string
    {
        return 'generate_interview_plan';
    }

    public function description(): string
    {
        return 'Generate a multi-round interview plan for a role (how many rounds, what each round evaluates, suggested interviewers/roles, and pass criteria).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string'],
                'level' => ['type' => 'string'],
                'number_of_rounds' => ['type' => 'integer'],
            ],
            'required' => ['role'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'interviews.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        if (blank($arguments['role'] ?? null)) {
            return ToolResult::fail('A role is required.');
        }

        if (! $this->gateway->isConfigured()) {
            return $this->modelUnavailable($this->gateway, 'generate an interview plan');
        }

        $rounds = $arguments['number_of_rounds'] ?? 'a sensible number of';

        $messages = [
            LlmMessage::system('You design interview processes. Output a numbered list of rounds; for each, state its purpose, what it evaluates, who should conduct it (by role, not a specific person), and the pass criteria.'),
            LlmMessage::user("Role: {$arguments['role']}\nLevel: ".($arguments['level'] ?? 'not specified')."\nUse {$rounds} rounds."),
        ];

        $text = $this->generateText($this->gateway, $messages, 'generation', $user);

        if ($text === null) {
            return $this->modelUnavailable($this->gateway, 'generate an interview plan');
        }

        return ToolResult::ok(
            data: ['interview_plan' => $text],
            summary: "Generated an interview plan for {$arguments['role']}.",
            type: 'text',
        );
    }
}
