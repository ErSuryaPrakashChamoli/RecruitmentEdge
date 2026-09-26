---
paths:
  - 'tests/**'
---

# Tests

## Pest helper functions are global — give them file-specific names
A top-level `function foo()` in a Pest test file, even one inside describe(), is declared globally. A name used in two files crashes the whole suite ("Cannot redeclare function") only when both files load together, which happens in the full or parallel run but not when running a single file. Prefix helpers with the feature (e.g. pipelineApplicationOn(), referralUser()) and check with `grep -rhoE "^\s*function [a-zA-Z_]+" tests | sort | uniq -d`.

## Pest toContain is variadic — never pass a failure message to it
expect($x)->toContain($needle, 'message') treats 'message' as a second needle, and ->not->toContain($needle, 'message') then passes whenever the message text is absent — a silently vacuous assertion (this hid real leaks during Phase 8.1 until a mutation check exposed it). For a custom message use expect(str_contains($x, $needle))->toBeFalse('message') / ->toBeTrue('message'). Mutation-check new security assertions: reintroduce the bug and confirm the test fails.
