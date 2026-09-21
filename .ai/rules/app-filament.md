---
paths:
  - 'app/Filament/**'
---

# App Filament

## Pick applications with ApplicationPicker, never a code-only Select
Any form field choosing a CandidateApplication must use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker::make() (chain ->required() etc.). Application codes alone aren't recognisable: its options read "name · mobile · APP code · REQ code · stage", search matches candidate name/mobile/code, and options + a validation rule are hierarchy-scoped via CandidateApplicationResource::getEloquentQuery(). Pass modifyOptionsQueryUsing to narrow only the listed options (e.g. InterviewForm lists Active applications) — the rule stays scope-only so editing older records still saves. Server-side lookups in actions should use ApplicationPicker::selectableApplications()->findOrFail().
