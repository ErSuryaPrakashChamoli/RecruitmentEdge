---
paths:
  - 'app/Services/Metrics/**'
---

# Metrics

## Governed metrics: one registered definition, read through MetricService
Phase 8.5: every headline number (dashboard, report, export, Copilot, alerts) comes from a MetricDefinition registered in MetricRegistry::DEFINITIONS and read via MetricService::get(key, MetricQuery) → MetricResult. Never compute a rate/median inline in a widget or tool. A definition's semantic fields are fingerprinted (MetricRegistryTest): change meaning → bump version + effective_from + record the new fingerprint. Periods are MetricPeriod (business timezone metrics.business_timezone, inclusive days; whereDateColumn/whereTimestampColumn — never whereBetween, arch test). Scope only via MetricScope (applications = current owner, requisitions = scopeVisibleTo; deleted applications never count). A withheld value is null with a MetricResultStatus (NoData/Unknown/InsufficientSample/NotApplicable), never 0; min sample from metrics.min_sample. Cache stores MetricResult::toCache() arrays only — cache.serializable_classes is false, a cached object comes back as __PHP_Incomplete_Class. Result details are plain data (enums as values). MetricStatus is Hiring Health's enum; the metric one is MetricResultStatus.
