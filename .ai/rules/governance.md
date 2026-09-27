---
paths:
  - 'app/Services/Governance/**,config/outcomes.php,config/metrics.php'
---

# Governance

## Config that changes history is fingerprinted to its rule version
Phase 8.6 (D8.6-028): ConfigurationFingerprint::GOVERNED keys (outcome checkpoints/grace/catch-up, outcome sample bands/learning checkpoint, metrics timezone/min_sample/compensation_min_group) are hashed and pinned in PINNED per rule version. Changing one needs a rule-version bump and a new pin (ConfigurationFingerprintTest prints the value). governance:audit (read-only, GovernanceAuditor) reports drift and pre-8.6 damage and never writes.
