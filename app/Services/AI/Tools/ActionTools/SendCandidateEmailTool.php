<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\Communication\Contracts\EmailProviderInterface;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * EXTERNAL risk: this actually sends an email to a candidate, so it always requires human
 * confirmation, even though the subject/body were already reviewed as a draft. Callers must pass
 * the final (possibly user-edited) subject/body — this tool never re-generates content itself.
 */
class SendCandidateEmailTool implements AiTool
{
    public function __construct(private readonly EmailProviderInterface $email) {}

    public function name(): string
    {
        return 'send_candidate_email';
    }

    public function description(): string
    {
        return 'Send an email to a candidate. Use draft_candidate_email first to prepare the content, then call this with the final subject/body. Always requires human approval.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
                'subject' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ],
            'required' => ['candidate_id', 'subject', 'body'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::External;
    }

    public function permission(): ?string
    {
        return 'candidates.update';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $candidate = Candidate::query()->find($arguments['candidate_id'] ?? null);

        if ($candidate === null) {
            return ToolResult::fail('Candidate not found.');
        }

        if (blank($candidate->email)) {
            return ToolResult::fail("{$candidate->full_name} has no email address on file.");
        }

        $this->email->send($candidate->email, (string) $arguments['subject'], (string) $arguments['body']);

        return ToolResult::ok(
            data: ['entity_type' => 'Candidate', 'entity_ids' => [$candidate->id]],
            summary: "Sent email to {$candidate->full_name} ({$candidate->email}).",
            type: 'action_result',
        );
    }
}
