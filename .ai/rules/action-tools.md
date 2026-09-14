---
paths:
  - 'app/Services/InterviewService.php,app/Filament/Resources/Interviews/**,app/Filament/Pages/InterviewWorkspace.php,app/Services/AI/Tools/ActionTools/ScheduleInterviewTool.php'
---

# Action Tools

## All interview scheduling goes through InterviewService::schedule()
Every creation path (Create page, Candidate 360 action, Interviews relation manager, Interview Calendar, AI ScheduleInterviewTool) must call InterviewService::schedule(): it creates the interview, advances the application to Interview Scheduled via StageTransitionService only when the current stage is earlier (forward-only, never throws for later stages), and notifies both interviewer and recruiter. It refuses non-Active applications. Reschedule (→ Rescheduled) and Hold (remarks required) also go through the service; InterviewStatus::unconfirmed() = Scheduled + Rescheduled and is what reminders/Action Center should use.
