---
paths:
  - 'app/Models/CandidateJoining.php,app/Services/EmployeeConversionService.php'
  - 'app/Models/*.php,app/Services/*.php'
---

# Models Services

## Joining risk level is computed, not stored; employee conversion links back via candidate_id
CandidateJoining::riskLevel() is always computed live (never cached/stored) from status + expected_doj + the `joining_risk_followup_days` recruitment_settings key — keep it that way so the traffic-light indicator is never stale. EmployeeConversionService::convert() requires JoiningStatus::Joined and guards against converting the same candidate twice via `employees.candidate_id` (unique) — Employee::candidate() / Candidate::employee() is the only link between recruitment and future HRMS employee records, so never create an Employee from a candidate through any other path.

## Never updateOrCreate() on a date-cast column with a plain string match
Eloquent's `date` cast serializes to a full "Y-m-d H:i:s" string for DB storage (the `date:Y-m-d` format parameter only affects array/JSON serialization, never persistence — see Illuminate\Database\Eloquent\Concerns\HasAttributes::addCastAttributesToArray). So `Model::updateOrCreate(['some_date_col' => $carbon->toDateString()], ...)` will never match an existing row and silently duplicate-insert (then fail the unique constraint on a second attempt). When upserting by a date-cast column, do it manually: `where(...)->whereDate('col', $date)->first()`, then `update()` or `create()` — see PerformanceEngine::snapshotFor() for the pattern. This applies to any future period-keyed snapshot/cache table (e.g. incentive calculations, future daily/monthly rollups).
