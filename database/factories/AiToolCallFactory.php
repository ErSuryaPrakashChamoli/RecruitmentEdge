<?php

namespace Database\Factories;

use App\Models\AiMessage;
use App\Models\AiToolCall;
use App\Services\AI\Actions\ApprovalAuthority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolCall>
 */
class AiToolCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => AiMessage::factory(),
            'tool_name' => 'search_candidates',
            'arguments' => ['query' => fake()->word()],
            'risk_level' => 'read',
            'status' => 'pending',
            'requires_confirmation' => false,
        ];
    }

    /**
     * Phase 8.4: like a real proposal, the call records its requester (the conversation owner), an
     * approval window and the requester's authority at the time — unless the test sets them.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (AiToolCall $call): void {
            $requester = $call->message?->conversation?->user;

            if ($requester === null) {
                return;
            }

            $call->requested_by ??= $requester->id;

            if ($call->requires_confirmation) {
                $call->expires_at ??= now()->addMinutes((int) config('ai.actions.pending_ttl_minutes', 30));
                $call->authority_fingerprint ??= app(ApprovalAuthority::class)->fingerprint($requester);
            }
        });
    }
}
