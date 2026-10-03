<?php

namespace App\Filament\Pages;

use App\Enums\AiMessageRole;
use App\Enums\AiToolCallStatus;
use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\Actions\ActionExecutor;
use App\Services\AI\Actions\ApprovalParameterPreview;
use App\Services\AI\Actions\ConfirmationGate;
use App\Services\AI\Exceptions\AiRateLimitExceededException;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Orchestrator\AiOrchestrator;
use App\Services\AI\Privacy\AiReference;
use App\Services\AI\Privacy\AiReferenceResolver;
use App\Services\HierarchyService;
use BackedEnum;
use DomainException;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
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
 *
 * Phase 8.1: the page context from the URL is authorized against the user's hierarchy before it
 * is used (and locked against Livewire tampering); AI output carries reference codes, which are
 * resolved to names here — for this viewer, at render time only. Conversations recorded before the
 * privacy boundary are shown read-only.
 */
class AiCopilot extends Page
{
    private const int RECENT_CONVERSATION_LIMIT = 15;

    /**
     * Page contexts that refer to no record, so need no visibility check.
     */
    private const array RECORDLESS_CONTEXTS = ['dashboard'];

    protected string $view = 'filament.pages.ai-copilot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'AI Copilot';

    protected static ?string $title = 'AI Recruitment Copilot';

    // Phase 8.4: locked — only the page's own (owner-checked) methods may switch conversations.
    #[Locked]
    public ?int $conversationId = null;

    public string $question = '';

    #[Locked]
    public ?string $contextType = null;

    #[Locked]
    public ?int $contextId = null;

    public bool $sending = false;

    public function mount(): void
    {
        $contextType = request()->query('context_type');
        $contextId = request()->query('context_id') ? (int) request()->query('context_id') : null;

        if ($this->contextIsVisible($contextType, $contextId)) {
            $this->contextType = $contextType;
            $this->contextId = $contextId;
        } elseif (filled($contextType)) {
            // Denied without saying whether the record exists; the user just gets a general chat.
            Log::notice('AI Copilot page context denied', ['user_id' => $this->user()->id, 'context_type' => is_string($contextType) ? $contextType : null]);
        }

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

    public function isLegacyConversation(): bool
    {
        return $this->conversation()->isLegacy();
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
                'content' => $this->resolver()->resolve($message->content, $this->user(), markdown: true),
                'tool_calls' => $message->toolCalls->map(fn (AiToolCall $call) => [
                    'id' => $call->id,
                    'tool_name' => $call->tool_name,
                    // Phase 8.4: an action past its approval window shows as expired straight away.
                    'status' => $call->status === AiToolCallStatus::Pending && $call->isExpired() ? AiToolCallStatus::Expired->value : $call->status->value,
                    'status_label' => $call->status === AiToolCallStatus::Pending && $call->isExpired() ? AiToolCallStatus::Expired->label() : $call->status->label(),
                    'risk_level' => $call->risk_level->label(),
                    'requires_confirmation' => $call->requires_confirmation,
                    'arguments' => $call->arguments,
                    'output' => $call->result?->output !== null ? $this->resolver()->resolveStructure($call->result->output, $this->user()) : null,
                    'success' => $call->result?->success,
                    'preview' => $call->status === AiToolCallStatus::Pending ? $this->approvalPreview($call) : [],
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

        if ($question === '' || $this->isLegacyConversation()) {
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

        if ($stillPending) {
            return;
        }

        // Phase 8.3: the decision is already committed. If the provider is unreachable for the
        // follow-up answer, say so plainly instead of an error page that looks like the action
        // failed (and invites approving it again).
        try {
            app(AiOrchestrator::class)->continueTurn($this->conversation(), $this->user());
        } catch (AiRateLimitExceededException $e) {
            $this->addSystemError($e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->addSystemError('Your decision was recorded, but the AI service is temporarily unavailable to continue the conversation.');
        }
    }

    /**
     * What a pending action would affect, resolved for the approver only (never sent to the
     * provider): the people behind the ids in the arguments and, for an email, the recipient
     * address the application will use.
     *
     * @return array<int, string>
     */
    private function approvalPreview(AiToolCall $call): array
    {
        $user = $this->user();
        $arguments = $call->arguments ?? [];
        $ids = fn (string $single, string $plural) => array_map('intval', array_filter([...(array) ($arguments[$plural] ?? []), $arguments[$single] ?? null]));
        $lines = [];

        $candidates = Candidate::query()->visibleTo($user)->whereKey($ids('candidate_id', 'candidate_ids'))->get();

        foreach ($candidates as $candidate) {
            $lines[] = 'Candidate: '.AiReference::candidate($candidate).' — '.$candidate->full_name;

            if ($call->tool_name === 'send_candidate_email') {
                $lines[] = 'Recipient: '.($candidate->email ?: 'no email address on file');
            }
        }

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);
        CandidateApplication::query()
            ->when($visibleIds !== null, fn (Builder $query) => $query->whereIn('recruiter_id', $visibleIds))
            ->whereKey($ids('application_id', 'application_ids'))
            ->with('candidate')
            ->get()
            ->each(function (CandidateApplication $application) use (&$lines): void {
                $lines[] = 'Application: '.AiReference::application($application).' — '.$application->candidate?->full_name;
            });

        foreach (['interviewer_employee_id' => 'Interviewer', 'recruiter_employee_id' => 'Assign to'] as $key => $label) {
            $employee = filled($arguments[$key] ?? null) ? Employee::query()->find((int) $arguments[$key]) : null;

            if ($employee !== null && app(HierarchyService::class)->canView($user, $employee)) {
                $lines[] = $label.': '.AiReference::employee($employee).' — '.$employee->fullName();
            }
        }

        // Phase 8.10 (P810-AI-01): every decision parameter, exactly as it will run.
        return [...$lines, ...app(ApprovalParameterPreview::class)->lines($arguments)];
    }

    private function contextIsVisible(mixed $type, ?int $id): bool
    {
        if (! is_string($type)) {
            return false;
        }

        if ($id === null) {
            return in_array($type, self::RECORDLESS_CONTEXTS, true);
        }

        $user = $this->user();

        return match ($type) {
            'candidate' => Candidate::query()->visibleTo($user)->whereKey($id)->exists(),
            'requisition' => RecruitmentRequisition::query()->visibleTo($user)->whereKey($id)->exists(),
            'employee' => ($employee = Employee::query()->find($id)) !== null && app(HierarchyService::class)->canView($user, $employee),
            default => false,
        };
    }

    private function resolver(): AiReferenceResolver
    {
        return app(AiReferenceResolver::class);
    }

    /**
     * Phase 8.4: the call must belong to a conversation the signed-in user owns — a tampered
     * conversation or tool-call id can never reach someone else's pending action.
     */
    private function toolCallInCurrentConversation(int $toolCallId): ?AiToolCall
    {
        return AiToolCall::query()
            ->whereHas('message', fn (Builder $message) => $message
                ->where('conversation_id', $this->conversationId)
                ->whereHas('conversation', fn (Builder $conversation) => $conversation->where('user_id', $this->user()->id)))
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
