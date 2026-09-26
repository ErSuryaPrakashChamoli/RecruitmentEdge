<?php

namespace App\Services\Intelligence;

use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\IntelligenceEvidence;
use App\Models\User;
use App\Services\Intelligence\Data\EvidenceItem;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * The only writer of intelligence_evidence (EDGE Intelligence provenance). Evidence is attached to
 * the intelligence record it explains and is append-only; a person may only verify or reject
 * AI-derived evidence, and that is audited.
 */
class EvidenceRecorder
{
    /**
     * @param  iterable<EvidenceItem>  $items
     */
    public function record(Model $owner, iterable $items, string $generator, string $generatorVersion): int
    {
        $now = now();
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'subject_key' => $item->subjectKey,
                'evidence_type' => $item->type->value,
                'label' => mb_substr($item->label, 0, 255),
                'value' => $item->value !== null ? mb_substr($item->value, 0, 500) : null,
                'numeric_value' => $item->numericValue,
                'source_type' => $item->source?->getMorphClass(),
                'source_id' => $item->source?->getKey(),
                'observed_at' => $item->observedAt,
                'generator' => $generator,
                'generator_version' => $generatorVersion,
                'ai_model' => $item->aiModel,
                'confidence' => $item->confidence,
                'explanation' => $item->explanation,
                'verification_status' => $item->verificationStatus()->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            IntelligenceEvidence::query()->insert($chunk);
        }

        return count($rows);
    }

    public function verify(IntelligenceEvidence $evidence, User $actor, VerificationStatus $status): IntelligenceEvidence
    {
        if (! $evidence->isAiDerived()) {
            throw new DomainException('Only AI-derived evidence needs human verification.');
        }

        if (! in_array($status, [VerificationStatus::Verified, VerificationStatus::Rejected], true)) {
            throw new DomainException('Evidence can only be verified or rejected.');
        }

        $previous = $evidence->verification_status;
        $evidence->forceFill(['verification_status' => $status, 'verified_by' => $actor->id, 'verified_at' => now()])->save();

        AuditLog::record($evidence, 'intelligence_evidence_verified', ['verification_status' => $previous->value], ['verification_status' => $status->value, 'by_user_id' => $actor->id]);

        return $evidence;
    }
}
