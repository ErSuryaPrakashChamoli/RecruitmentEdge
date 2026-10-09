<?php

namespace App\Enums;

/**
 * SaaS-5: what a platform operator may do in the platform control plane. Capabilities come from the
 * operator's existing platform roles (rolesGranting()), never from anything in a tenant — not CHRO,
 * not ownership, not a billing permission — and grant nothing inside any tenant.
 */
enum PlatformCapability: string
{
    case TenantsView = 'platform.tenants.view';
    case TenantsManage = 'platform.tenants.manage';
    case SupportManage = 'platform.support.manage';
    case ComplianceManage = 'platform.compliance.manage';
    case AuditView = 'platform.audit.view';
    case OperationsView = 'platform.operations.view';
    case DeletionManage = 'platform.deletion.manage';

    /**
     * Commercial V1: plan catalog, subscriptions, invoices and payments, and the billing actions on
     * them. Administrators only, for now — not the final commercial RBAC (docs/platform-commercial-ui.md).
     */
    case CommercialManage = 'platform.commercial.manage';

    /**
     * The smallest safe mapping of the SaaS-2 roles (docs/saas-5-decision-register.md D-S5-01).
     *
     * @return list<PlatformRole>
     */
    public function rolesGranting(): array
    {
        return match ($this) {
            self::TenantsView => [PlatformRole::Administrator, PlatformRole::Support, PlatformRole::Compliance],
            self::TenantsManage => [PlatformRole::Administrator],
            self::SupportManage => [PlatformRole::Support],
            self::ComplianceManage => [PlatformRole::Compliance],
            self::AuditView => [PlatformRole::Administrator, PlatformRole::Compliance],
            self::OperationsView => [PlatformRole::Administrator, PlatformRole::Support],
            self::DeletionManage => [PlatformRole::Administrator, PlatformRole::Compliance],
            self::CommercialManage => [PlatformRole::Administrator],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TenantsView => 'View tenants',
            self::TenantsManage => 'Manage tenants (lifecycle, ownership)',
            self::SupportManage => 'Request and use support access',
            self::ComplianceManage => 'Compliance exports',
            self::AuditView => 'View the platform audit',
            self::OperationsView => 'View operations',
            self::DeletionManage => 'Request, approve and cancel tenant deletion',
            self::CommercialManage => 'View and manage subscriptions, invoices and payments',
        };
    }
}
