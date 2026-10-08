---
paths:
  - 'app/Models/Department.php,app/Models/Designation.php,app/Models/Location.php,app/Models/CandidateSource.php,app/Models/RecruitmentRejectionReason.php,app/Services/MasterDataLifecycleService.php'
---

# Models Models Services

## Master data is archived, never deleted — change it only through MasterDataLifecycleService
Phase 8.6: Department, Designation, Location, CandidateSource and RecruitmentRejectionReason use GovernedMasterData + Auditable: code immutable, forceDelete throws (DB cascades would delete costs/targets/rules and blank outcome snapshots). Deactivate/archive/restore go through MasterDataLifecycleService (settings.manage, required reason via AuditLog::withReason, archive refused while open work uses the record — openWork()). Restore returns Inactive. Policies: forceDelete*/deleteAny/restoreAny false (no bulk). Display relations to these models are ->withTrashed(); form pickers must pass ActiveMasterDataOptions::scope('x_id') and list columns MasterDataLabel::for('rel'). Models taking them up use ReferencesActiveMasterData (inactive/archived refused on create/change only). The app finds sources by code (CandidateSource::CODE_WEBSITE / CODE_EMPLOYEE_REFERRAL, protected), never by name. Seeders match withTrashed()->firstOrCreate by code.
