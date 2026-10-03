---
paths:
  - 'app/Filament/Pages/AiCopilot.php,app/Services/AI/Actions/**,app/Services/AI/Tools/ActionTools/**,resources/views/filament/pages/ai-copilot.blade.php'
---

# Views Filament Pages

## The AI approval card shows every argument the action will run with
P810-AI-01: AiCopilot::approvalPreview() = scoped entity lines (candidates, applications, interviewer, assignee) + ApprovalParameterPreview::lines($call->arguments) for every other stored argument (stage, rejection reason, remarks, schedule, mode, link, follow-up, subject, full body; unknown keys listed generically, never hidden). The executor runs the stored arguments, so what is shown is what runs. A new approval-required tool argument must be in ApprovalParameterPreview::ENTITY_ARGUMENTS (and resolved by the page) or produce a line — tests/Feature/Ai/ApprovalCardParametersTest enforces it over the ToolRegistry. Preview is UI-only, never sent to the model. Friction / second approver remain D8.10-012.
