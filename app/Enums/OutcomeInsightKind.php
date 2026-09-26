<?php

namespace App\Enums;

/**
 * What an Outcome Loop learning insight proposes (Phase 8.2). Every kind needs a human decision.
 */
enum OutcomeInsightKind: string
{
    case RoleDnaLearning = 'role_dna_learning';
    case SourcePattern = 'source_pattern';

    public function label(): string
    {
        return match ($this) {
            self::RoleDnaLearning => 'Role DNA learning suggestion',
            self::SourcePattern => 'Source pattern for Hiring Memory',
        };
    }
}
