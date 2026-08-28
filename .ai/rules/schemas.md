---
paths:
  - 'app/Filament/Resources/**/Schemas/*.php'
---

# Schemas

## Layout components (Section, Fieldset, Tabs, Wizard, Grid, Group) live under Filament\Schemas\Components, not Filament\Forms\Components
In this installed Filament v5, form/infolist layout components were moved to the unified `filament/schemas` package. Field components (TextInput, Select, DatePicker, Textarea, FileUpload, Toggle, etc.) still live under `Filament\Forms\Components\*`, but layout/grouping components (Section, Fieldset, Tabs, Wizard, Grid, Group, Flex) live under `Filament\Schemas\Components\*`. Importing `Filament\Forms\Components\Section` compiles fine (no error until the class is actually loaded) but throws "Class not found" the moment the form renders — this bit 5 files in one pass (RecruitmentRequisitionForm, RecruitmentDailyTargetForm, OfferForm, CandidateForm, RecruitmentIncentiveRuleForm) before being caught by a user report. Always verify a Filament class's real namespace against `vendor/filament/*/src` before writing the import from memory.
