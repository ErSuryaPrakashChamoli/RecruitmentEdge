<?php

namespace App\Services\Automation\Actions;

use App\Enums\AutomationActionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalized result of one automation action.
 */
final readonly class ActionOutcome
{
    public function __construct(
        public AutomationActionStatus $status,
        public string $summary,
        public ?Model $target = null,
        public ?string $error = null,
    ) {}

    public static function completed(string $summary, ?Model $target = null): self
    {
        return new self(AutomationActionStatus::Completed, $summary, $target);
    }

    public static function skipped(string $summary): self
    {
        return new self(AutomationActionStatus::Skipped, $summary);
    }

    public static function failed(string $error): self
    {
        return new self(AutomationActionStatus::Failed, 'Failed', error: $error);
    }
}
