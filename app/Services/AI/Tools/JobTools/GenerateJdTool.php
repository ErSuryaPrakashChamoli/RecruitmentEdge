<?php

namespace App\Services\AI\Tools\JobTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * Pure text generation — nothing is written to the database, so this is Recommend risk (no
 * confirmation needed). There is no stored "job description" field on RecruitmentRequisition
 * today; the generated text is returned to the user to copy/use, not auto-saved anywhere.
 */
class GenerateJdTool implements AiTool
{
    public function __construct(private readonly AiGateway $gateway) {}

    public function name(): string
    {
        return 'generate_jd';
    }

    public function description(): string
    {
        return 'Draft a job description for a role, given a title and optional details (department, experience range, skills, location, salary range).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'department' => ['type' => 'string'],
                'experience_range' => ['type' => 'string'],
                'skills' => ['type' => 'string'],
                'location' => ['type' => 'string'],
                'salary_range' => ['type' => 'string'],
            ],
            'required' => ['title'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'requisitions.create';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        if (! $this->gateway->isConfigured()) {
            return ToolResult::fail('AI is not configured, so I cannot draft a job description right now.');
        }

        $details = collect($arguments)->except('title')->filter()->map(fn ($v, $k) => ucfirst(str_replace('_', ' ', $k)).": {$v}")->implode("\n");

        $messages = [
            LlmMessage::system('You are a recruitment copywriter. Write a clear, structured, non-discriminatory job description in markdown with sections: Role Summary, Responsibilities, Requirements, and (if salary given) Compensation. Do not invent company-specific facts not provided.'),
            LlmMessage::user("Job title: {$arguments['title']}\n{$details}"),
        ];

        $response = $this->gateway->generate($messages, [], 'generation', $user);

        return ToolResult::ok(
            data: ['job_description' => $response->content],
            summary: "Drafted a job description for {$arguments['title']}.",
            type: 'text',
        );
    }
}
