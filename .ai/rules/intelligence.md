---
paths:
  - 'app/Services/Intelligence/**'
---

# Intelligence

## EDGE Intelligence: facts vs AI, evidence for everything, never on render
Phase 7 intelligence is deterministic first: Role DNA (RoleDnaBuilder/RoleDnaService), Talent Signal (TalentSignalCalculator rules talent-signal/1), Hiring Health (hiring-health/1), Risk Radar (risk-radar/1), Rediscovery and Hiring Memory. Every persisted value records evidence through EvidenceRecorder (append-only; only verification may change). AI goes only through IntelligenceAiService → AiGateway::structured/generate in queued jobs, role-level data only (no candidate names/contacts/pay), validated + fairness-filtered, stored as unconfirmed ai_suggestion until a person confirms. Never compute intelligence or call AI while rendering a page — show persisted snapshots and their age; refresh is an explicit action or intelligence:refresh. Culture-fit rating is excluded. Intelligence never changes a hiring decision.
