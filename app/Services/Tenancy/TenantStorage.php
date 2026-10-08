<?php

namespace App\Services\Tenancy;

/**
 * SaaS-1: every file written for a tenant lives under tenants/{tenant_id}/{area}/… on its disk —
 * resumes, candidate and joining documents, offer letters and templates, AI documents, employee
 * photos, interviewer imports and exports. The prefix keeps one tenant's files apart on any
 * filesystem (local today, S3-compatible object storage later) and lets a tenant's files be
 * exported or purged as a whole.
 *
 * Files stored before SaaS-1 keep their path: they all belong to Tenant #1 and are always reached
 * through their owning record (PrivateFileController::OWNERS), never by path alone.
 */
final class TenantStorage
{
    public const ROOT = 'tenants';

    public static function path(string $area): string
    {
        return self::ROOT.'/'.TenantContext::current()->requireId().'/'.trim($area, '/');
    }
}
