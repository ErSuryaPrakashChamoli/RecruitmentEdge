<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * RECOMMEND risk: produces an editable draft only — nothing is sent. Sending is a separate
 * SendCandidateEmailTool call the user must explicitly approve (spec section 38: "all external
 * messages should be editable before sending").
 */
class DraftCandidateEmailTool implements AiTool
{
    public function __construct(private readonly AiGateway $gateway) {}

    public function name(): string
    {
        return 'draft_candidate_email';
    }

    public function description(): string
    {
        return 'Draft an email to a candidate (interview invitation, rejection, follow-up reminder, general update). Produces an editable draft only — does not send anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
                'purpose' => ['type' => 'string', 'description' => 'e.g. interview invitation, rejection, follow-up, offer reminder'],
                'key_points' => ['type' => 'string', 'description' => 'Any specific details to include, e.g. interview time/location'],
            ],
            'required' => ['candidate_id', 'purpose'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'candidates.update';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        if (! $this->gateway->isConfigured()) {
            return ToolResult::fail('AI is not configured, so I cannot draft this email right now.');
        }

        $candidate = Candidate::query()->find($arguments['candidate_id'] ?? null);

        if ($candidate === null) {
            return ToolResult::fail('Candidate not found.');
        }

        if (blank($candidate->email)) {
            return ToolResult::fail("{$candidate->full_name} has no email address on file.");
        }

        $messages = [
            LlmMessage::system('You draft short, professional, warm recruitment emails. Output plain text with a clear subject line on the first line prefixed "Subject: ", then a blank line, then the body. Never invent specific dates/times/salary figures not given to you.'),
            LlmMessage::user("Candidate: {$candidate->full_name}\nPurpose: {$arguments['purpose']}\nKey points: ".($arguments['key_points'] ?? 'none given')),
        ];

        $response = $this->gateway->generate($messages, [], 'generation', $user);
        [$subject, $body] = $this->splitSubjectAndBody((string) $response->content);

        return ToolResult::ok(
            data: ['candidate_id' => $candidate->id, 'candidate_email' => $candidate->email, 'subject' => $subject, 'body' => $body],
            summary: "Drafted a {$arguments['purpose']} email for {$candidate->full_name}. Review it, then use send_candidate_email to send.",
            type: 'email_draft',
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitSubjectAndBody(string $text): array
    {
        if (preg_match('/^Subject:\s*(.+)\n+([\s\S]*)$/i', trim($text), $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return ['Update on your application', $text];
    }
}
