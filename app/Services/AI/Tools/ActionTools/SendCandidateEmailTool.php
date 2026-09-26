<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\MessageContext;
use DomainException;

/**
 * EXTERNAL risk: this actually sends an email to a candidate, so it always requires human
 * confirmation, even though the subject/body were already reviewed as a draft. Callers must pass
 * the final (possibly user-edited) subject/body — this tool never re-generates content itself.
 */
class SendCandidateEmailTool implements AiTool
{
    use ScopesToHierarchy;

    public function __construct(private readonly CommunicationService $communications) {}

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
        $candidate = $this->scopeCandidatesVisibleTo(Candidate::query(), $user)->find($arguments['candidate_id'] ?? null);

        if ($candidate === null) {
            return ToolResult::fail('Candidate not found, or not visible to you.');
        }

        if (blank($arguments['subject'] ?? null) || blank($arguments['body'] ?? null)) {
            return ToolResult::fail('Both a subject and a body are required to send an email.');
        }

        if (blank($candidate->email)) {
            return ToolResult::fail("{$candidate->full_name} has no email address on file.");
        }

        // Phase 5: goes through the Communication Center like every other candidate message —
        // preference/consent checks, the timeline, audit and queued delivery with retries.
        try {
            $communication = $this->communications->send(
                CommunicationChannel::Email,
                new MessageContext($candidate),
                subject: (string) $arguments['subject'],
                body: (string) $arguments['body'],
                actor: $user->employee,
                trigger: CommunicationTrigger::Ai,
            );
        } catch (DomainException $e) {
            return ToolResult::fail($e->getMessage());
        }

        if ($communication->status === CommunicationStatus::Blocked) {
            return ToolResult::fail("Email to {$candidate->full_name} was not sent: {$communication->blocked_reason}");
        }

        return ToolResult::ok(
            data: ['entity_type' => 'Candidate', 'entity_ids' => [$candidate->id], 'communication' => $communication->public_id],
            summary: "Queued an email to {$candidate->full_name} ({$candidate->email}).",
            type: 'action_result',
        );
    }
}
