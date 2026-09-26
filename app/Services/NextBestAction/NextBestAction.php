<?php

namespace App\Services\NextBestAction;

use App\Enums\ActionPriority;
use App\Enums\RecruiterActionType;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One deterministic next-best-action recommendation (Phase 6). Every field is derived from
 * recorded facts by a named rule (`source`) — no AI. Phase 7 (EDGE Intelligence) adds the
 * evidence behind it and the metric it affects; `confidence` stays null for deterministic rules
 * (they are facts, not estimates) and is reserved for AI-derived recommendations.
 */
final readonly class NextBestAction
{
    /**
     * @param  array<int, string>  $evidence
     */
    public function __construct(
        public string $source,
        public ActionPriority $priority,
        public RecruiterActionType $type,
        public string $suggestedAction,
        public string $reason,
        public Model $entity,
        public ?Employee $owner = null,
        public ?CarbonInterface $dueAt = null,
        public ?string $suggestedTool = null,
        public array $evidence = [],
        public ?string $metric = null,
        public ?string $confidence = null,
    ) {}

    /**
     * The coarse priority the AI copilot tool has always returned.
     */
    public function legacyPriority(): string
    {
        return match ($this->priority) {
            ActionPriority::Critical, ActionPriority::High => 'high',
            ActionPriority::Medium => 'medium',
            ActionPriority::Low => 'low',
        };
    }

    /**
     * @return array{source: string, priority: string, type: string, suggested_action: string, reason: string, entity_type: string, entity_id: int|string, owner: string|null, due_at: string|null, evidence: array<int, string>, metric: string|null, confidence: string|null}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'priority' => $this->priority->value,
            'type' => $this->type->value,
            'suggested_action' => $this->suggestedAction,
            'reason' => $this->reason,
            'entity_type' => class_basename($this->entity),
            'entity_id' => $this->entity->getKey(),
            'owner' => $this->owner?->fullName(),
            'due_at' => $this->dueAt?->toIso8601String(),
            'evidence' => $this->evidence,
            'metric' => $this->metric,
            'confidence' => $this->confidence,
        ];
    }
}
