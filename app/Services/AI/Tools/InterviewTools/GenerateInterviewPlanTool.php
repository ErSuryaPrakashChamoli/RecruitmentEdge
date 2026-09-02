<?php

namespace App\Services\AI\Tools\InterviewTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\Contracts\AiTool;

class GenerateInterviewPlanTool implements AiTool
{
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
        if (! $this->gateway->isConfigured()) {
            return ToolResult::fail('AI is not configured, so I cannot generate an interview plan right now.');
        }

        $rounds = $arguments['number_of_rounds'] ?? 'a sensible number of';

        $messages = [
            LlmMessage::system('You design interview processes. Output a numbered list of rounds; for each, state its purpose, what it evaluates, who should conduct it (by role, not a specific person), and the pass criteria.'),
            LlmMessage::user("Role: {$arguments['role']}\nLevel: ".($arguments['level'] ?? 'not specified')."\nUse {$rounds} rounds."),
        ];

        $response = $this->gateway->generate($messages, [], 'generation', $user);

        return ToolResult::ok(
            data: ['interview_plan' => $response->content],
            summary: "Generated an interview plan for {$arguments['role']}.",
            type: 'text',
        );
    }
}
