---
paths:
  - 'app/Filament/Resources/Interviews/Schemas/InterviewForm.php,app/Models/Interviewer.php,app/Services/InterviewerImportService.php'
---

# Schemas Models Services

## Interviewer dropdown only lists the administrator-managed interviewer list
InterviewForm::schedulingFields() builds interviewer_id options from Interviewer::selectOptions(): active rows in `interviewers` (managed at Administration → Interviewers, gated by settings.manage, bulk-loaded from Excel by Emp ID = employees.employee_code via InterviewerImportService). On edit the interview's current interviewer is kept selectable even if later removed. Filament validates Select values against options, so any test that schedules through a form/action must use Interviewer::factory()->create()->employee_id, not a bare Employee. InterviewService::schedule() itself (and the AI ScheduleInterviewTool) does NOT enforce the list. The Add Feedback interviewer select is deliberately unrestricted.
