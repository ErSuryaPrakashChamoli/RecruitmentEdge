---
paths:
  - 'tests/**'
---

# Tests

## Pest helper functions are global — give them file-specific names
A top-level `function foo()` in a Pest test file, even one inside describe(), is declared globally. A name used in two files crashes the whole suite ("Cannot redeclare function") only when both files load together, which happens in the full or parallel run but not when running a single file. Prefix helpers with the feature (e.g. pipelineApplicationOn(), referralUser()) and check with `grep -rhoE "^\s*function [a-zA-Z_]+" tests | sort | uniq -d`.
