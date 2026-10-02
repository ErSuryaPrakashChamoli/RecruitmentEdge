---
paths:
  - 'app/Services/Metrics/**'
---

# Metrics

## Governed metrics: one registered definition, read through MetricService
Phase 8.5: every headline number (dashboard, report, export, Copilot, alerts) comes from a MetricDefinition registered in MetricRegistry::DEFINITIONS and read via MetricService::get(key, MetricQuery) → MetricResult. Never compute a rate/median inline in a widget or tool. A definition's semantic fields are fingerprinted (MetricRegistryTest): change meaning → bump version + effective_from + record the new fingerprint. Periods are MetricPeriod (business timezone metrics.business_timezone, inclusive days; whereDateColumn/whereTimestampColumn — never whereBetween, arch test). Scope only via MetricScope (applications = current owner, requisitions = scopeVisibleTo; deleted applications never count). A withheld value is null with a MetricResultStatus (NoData/Unknown/InsufficientSample/NotApplicable), never 0; min sample from metrics.min_sample. Cache stores MetricResult::toCache() arrays only — cache.serializable_classes is false, a cached object comes back as __PHP_Incomplete_Class. Result details are plain data (enums as values). MetricStatus is Hiring Health's enum; the metric one is MetricResultStatus.

## Large medians use the in-place helpers, not Collection::median()
Phase 8.9 (P89-PERF-028/029). Collection::median() filters, sorts and re-indexes a copy of every value, so a metric over a million rows held about 4× its data; time_in_stage took 426 MB at 1M. For metrics over unbounded populations, collect plain lists and use MetricDefinition::medianSortingInPlace() / medianOfSortedLists(). They return exactly what median() returns. For the mean, array_sum() the list in collected order before sorting, as avg() does. Prove any change byte-identical on the benchmark (p89_parity) and keep the MetricScaleShapeTest property test green.
