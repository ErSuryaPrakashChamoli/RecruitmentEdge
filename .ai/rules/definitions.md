---
paths:
  - 'app/Models/RecruitmentSetting.php,app/Services/RecruitmentSettingService.php,app/Filament/Resources/RecruitmentSettings/**,app/Services/Metrics/Definitions/SlaLegCompliance.php'
---

# Definitions

## Governed settings change only via RecruitmentSettingService; historical targets use valueAt()
Phase 8.6 (D8.6-012/013): RecruitmentSetting::DEFINITIONS keys are written by RecruitmentSettingService::update (settings.manage, required reason, cross-field rules) which appends recruitment_setting_changes. The raw settings resource is read-only (no create/edit/delete). A value that grades the past must be read as of the event: valueAt(key, $at) — sla.leg_compliance v2 uses the target in force at each leg's end; timeToHireSummary the target at period end. Before the first recorded change valueAt returns that change's old value; without history, the current value. Cache entries are ['value'=>…]/['missing'=>true] under prefix recruitment_setting:v2: (never cache a caller's default).
