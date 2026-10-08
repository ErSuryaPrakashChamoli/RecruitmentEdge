---
paths:
  - 'app/Filament/Resources/Interviews/Schemas/InterviewForm.php,app/Models/Interviewer.php,app/Services/InterviewerImportService.php,app/Services/InterviewService.php'
---

# Services Services

## New interviews need an active listed interviewer (Phase 8.6)
InterviewService::schedule now refuses an interviewer who is not on the active interviewer list (Interviewer::isActiveInterviewer) — this supersedes "schedule() does NOT enforce the list". Interviewers are deactivated (reason, audited), never deleted. The import runs in one transaction, audits a summary (interviewers_imported) and reports deactivated interviewers instead of reactivating them. Tests scheduling interviews must use Interviewer::factory()->create()->employee_id; slot factories already do.
