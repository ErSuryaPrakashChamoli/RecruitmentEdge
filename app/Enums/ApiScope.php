<?php

namespace App\Enums;

/**
 * SaaS-6: what an API credential may be used for. A scope only narrows: the request must also be
 * allowed to the credential's owner by the existing permissions, policies and hierarchy, by the
 * tenant's entitlements and by its lifecycle. Read scopes never write; the only write is submitting
 * an applicant to a live job posting (no external system selects, rejects, hires, offers or onboards).
 */
enum ApiScope: string
{
    case MasterDataRead = 'master_data:read';
    case RequisitionsRead = 'requisitions:read';
    case CandidatesRead = 'candidates:read';
    case ApplicationsRead = 'applications:read';
    case ApplicationsWrite = 'applications:write';

    public function label(): string
    {
        return match ($this) {
            self::MasterDataRead => 'Read departments, locations and designations',
            self::RequisitionsRead => 'Read requisitions and job postings',
            self::CandidatesRead => 'Read candidates',
            self::ApplicationsRead => 'Read applications',
            self::ApplicationsWrite => 'Submit applicants to live job postings',
        };
    }

    /**
     * The tenant permission the owner needs for the scope to be useful (null: any member) — a scope
     * is offered only to an owner who holds it, and the policy decides every request anyway.
     */
    public function permission(): ?string
    {
        return match ($this) {
            self::MasterDataRead => null,
            self::RequisitionsRead => 'requisitions.viewAny',
            self::CandidatesRead, self::ApplicationsRead => 'candidates.viewAny',
            self::ApplicationsWrite => 'candidates.create',
        };
    }
}
