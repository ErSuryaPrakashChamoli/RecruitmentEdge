<?php

namespace App\Filament\Pages;

use App\Enums\AiMessageRole;
use App\Enums\AiToolCallStatus;
use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use App\Services\AI\Actions\ConfirmationGate;
use App\Services\AI\Exceptions\AiRateLimitExceededException;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Orchestrator\AiOrchestrator;
use BackedEnum;
use DomainException;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The global AI Recruitment Copilot — the single chat surface for internal data, recruitment
 * knowledge, external research, and permission-gated actions (spec sections 30-33). With no LLM
 * provider configured, AiOrchestrator answers from a keyword search of the knowledge base
 * (AiAssistantService) instead of failing.
 *
 * Users can start a new conversation or switch between their own recent ones; every conversation
 * lookup here is restricted to the signed-in user's own rows, so a tampered Livewire property can
 * never open (or approve actions inside) someone else's conversation.
 *
 * Note on streaming: this page calls AiOrchestrator::ask() synchronously rather than wiring
 * Livewire's native stream() to a live SSE feed — see OpenAiProvider's docblock for why true
 * token-level streaming isn't implemented without a live key to verify the event schema against.
 */
class AiCopilot extends Page
{
    private const int RECENT_CONVERSATION_LIMIT = 15;

    protected string $view = 'filament.pages.ai-copilot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'AI Copilot';

    protected static ?string $title = 'AI Recruitment Copilot';

    public ?int $conversationId = null;

    public string $question = '';

    public ?string $contextType = null;

    public ?int $contextId = null;

    public bool $sending = false;

    public function mount(): void
    {
        $this->contextType = request()->query('context_type');
        $this->contextId = request()->query('context_id') ? (int) request()->query('context_id') : null;

        $conversation = $this->findOrCreateConversation();
        $this->conversationId = $conversation->id;
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('ai.query');
    }

    /**
     * Contextual "Ask AI" entry point (spec section 31) — resolves a link into the Copilot seeded
     * with the given page context, so the user never has to repeat "for candidate X" in the prompt.
     */
    public static function linkForContext(string $contextType, ?int $contextId = null): string
    {
        return static::getUrl(array_filter(['context_type' => $contextType, 'context_id' => $contextId]));
    }

    public function isAiConfigured(): bool
    {
        return app(AiGateway::class)->isConfigured();
    }

    public function canApproveActions(): bool
    {
        return app(ConfirmationGate::class)->canApprove($this->user());
    }

    /**
     * The signed-in user's own most recent conversations, for the conversation switcher.
     *
     * @return Collection<int, AiConversation>
     */
    public function recentConversations(): Collection
    {
        return $this->ownConversations()
            ->latest('last_message_at')
            ->latest('id')
            ->limit(self::RECENT_CONVERSATION_LIMIT)
            ->get(['id', 'title', 'context_type', 'last_message_at']);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function visibleMessages(): Collection
    {
        return $this->conversation()->messages()
            ->whereIn('role', [AiMessageRole::User, AiMessageRole::Assistant])
            ->with(['toolCalls.result'])
            ->oldest('id')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role->value,
                'content' => $message->content,
                'tool_calls' => $message->toolCalls->map(fn (AiToolCall $call) => [
                    'id' => $call->id,
                    'tool_name' => $call->tool_name,
                    'status' => $call->status->value,
                    'status_label' => $call->status->label(),
                    'risk_level' => $call->risk_level->label(),
                    'requires_confirmation' => $call->requires_confirmation,
                    'arguments' => $call->arguments,
                    'output' => $call->result?->output,
                    'success' => $call->result?->success,
                ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public function suggestedPrompts(): array
    {
        return match ($this->contextType) {
            'candidate' => [
                'Summarize this candidate.',
                'What is the next best step for this candidate?',
                'Generate interview questions for this candidate.',
                'Find likely duplicates for this candidate.',
            ],
            'requisition' => [
                'Show the pipeline for this requisition.',
                'Why might we be getting poor applicants for this role?',
                'Improve this job description.',
                'Is this requisition at risk?',
            ],
            'employee' => [
                "Analyze this recruiter's performance this month.",
                'Compare this recruiter to others in the team.',
            ],
            'dashboard' => [
                'Explain this dashboard.',
                'What changed this month?',
                'What should I focus on today?',
            ],
            default => [
                'What needs my attention today?',
                'Which follow-ups are overdue?',
                'Which candidates are stuck for more than 7 days?',
                'Analyze our recruitment funnel for the last 30 days.',
                'Create a hiring plan for 50 sales executives in 45 days.',
            ],
        };
    }

    public function newConversation(): void
    {
        $this->conversationId = $this->createConversation()->id;
        $this->question = '';
    }

    public function switchConversation(int|string|null $conversationId): void
    {
        $conversation = filled($conversationId)
            ? $this->ownConversations()->find((int) $conversationId)
            : null;

        if ($conversation === null || ! $this->user()->can('update', $conversation)) {
            return;
        }

        $this->conversationId = $conversation->id;
        $this->question = '';
    }

    public function ask(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->question = '';
        $this->sending = true;

        try {
            app(AiOrchestrator::class)->ask($this->conversation(), $question, $this->user());
        } catch (AiRateLimitExceededException $e) {
            $this->addSystemError($e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->addSystemError("I couldn't complete that just now because the AI service is temporarily unavailable. Please try again.");
        } finally {
            $this->sending = false;
        }
    }

    public function approveToolCall(int $toolCallId): void
    {
        $toolCall = $this->toolCallInCurrentConversation($toolCallId);

        if ($toolCall === null) {
            return;
        }

        try {
            app(ActionExecutor::class)->approve($toolCall, $this->user());
            $this->continueIfResolved($toolCall);
        } catch (DomainException|AiRateLimitExceededException $e) {
            $this->addSystemError($e->getMessage());
        }
    }

    public function rejectToolCall(int $toolCallId): void
    {
        $toolCall = $this->toolCallInCurrentConversation($toolCallId);

        if ($toolCall === null) {
            return;
        }

        try {
            app(ActionExecutor::class)->reject($toolCall, $this->user());
            $this->continueIfResolved($toolCall);
        } catch (DomainException $e) {
            $this->addSystemError($e->getMessage());
        }
    }

    private function continueIfResolved(AiToolCall $toolCall): void
    {
        $stillPending = $toolCall->message->toolCalls()->where('status', AiToolCallStatus::Pending)->exists();

        if (! $stillPending) {
            app(AiOrchestrator::class)->continueTurn($this->conversation(), $this->user());
        }
    }

    private function toolCallInCurrentConversation(int $toolCallId): ?AiToolCall
    {
        return AiToolCall::query()
            ->whereHas('message', fn (Builder $message) => $message->where('conversation_id', $this->conversationId))
            ->find($toolCallId);
    }

    private function conversation(): AiConversation
    {
        return $this->ownConversations()->findOrFail($this->conversationId);
    }

    /**
     * @return Builder<AiConversation>
     */
    private function ownConversations(): Builder
    {
        return AiConversation::query()->where('user_id', $this->user()->id);
    }

    private function user(): User
    {
        /** @var User */
        return Filament::auth()->user();
    }

    private function findOrCreateConversation(): AiConversation
    {
        $existing = $this->ownConversations()
            ->where('context_type', $this->contextType)
            ->where('context_id', $this->contextId)
            ->where('status', 'active')
            ->latest('last_message_at')
            ->first();

        return $existing ?? $this->createConversation();
    }

    private function createConversation(): AiConversation
    {
        return AiConversation::query()->create([
            'user_id' => $this->user()->id,
            'context_type' => $this->contextType,
            'context_id' => $this->contextId,
            'title' => AiOrchestrator::DEFAULT_TITLE,
            'status' => 'active',
            'last_message_at' => now(),
        ]);
    }

    private function addSystemError(string $message): void
    {
        $this->conversation()->messages()->create([
            'role' => AiMessageRole::Assistant,
            'content' => $message,
        ]);
    }
}
