---
paths:
  - 'app/Filament/Resources/**,app/Filament/Pages/*.php'
---

# Resources Filament Pages

## Catch DomainException in Filament actions that call domain services
Services like InterviewService/StageTransitionService throw DomainException for business rules (e.g. "feedback required before completing an interview"). An uncaught one inside a Filament action closure renders a full 500 error page over the panel instead of a validation message. In the performX() mutation helper, catch DomainException, send a danger Notification with $e->getMessage(), then `throw new Halt` (Filament\Support\Exceptions\Halt) to abort the action cleanly — see InterviewsTable::performComplete().

Also: any Select whose sibling field uses ->visible(fn (Get $get) => ...) must be ->live(), or the dependent field never appears and the user hits the service guard for the missing value.
