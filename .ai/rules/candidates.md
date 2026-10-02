---
paths:
  - 'app/Models/Candidate.php,app/Services/CandidateSearchTerm.php,app/Filament/Resources/Candidates/**'
---

# Candidates

## Candidate scope is a semi-join; exact identifiers use normalized columns
Phase 8.9 (P89-PERF-002/003):

- **Scope.** Candidate::visibleTo is `id IN (applications of visible recruiters UNION candidates created by the user)`. Never an OR with a dependent subquery: that was a full scan, 7 s at 1M.
- **Search.** Global and table search first try CandidateSearchTerm::applyExact:
  - CAND code;
  - email via email_normalized;
  - a phone of 10 or more digits via mobile_normalized / alternate_mobile_normalized.

  Only when that doesn't apply does search fall back to the substring LIKE. Pass `email: false` where email must not be searchable (CommandPalette, CandidatePicker).
- **No engine.** Do not introduce FULLTEXT or a search engine without decision D8.9-015.
