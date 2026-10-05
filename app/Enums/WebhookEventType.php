<?php

namespace App\Enums;

/**
 * SaaS-6: the outbound webhook event catalogue (D-S6-O6). Payloads are thin — identifiers and
 * non-personal state only; a receiver reads details through the API with its own credential.
 */
enum WebhookEventType: string
{
    case CandidateCreated = 'candidate.created';
    case ApplicationCreated = 'application.created';
    case ApplicationStageChanged = 'application.stage_changed';
    case RequisitionStatusChanged = 'requisition.status_changed';

    public function label(): string
    {
        return match ($this) {
            self::CandidateCreated => 'Candidate created',
            self::ApplicationCreated => 'Application created',
            self::ApplicationStageChanged => 'Application stage changed',
            self::RequisitionStatusChanged => 'Requisition status changed',
        };
    }
}
