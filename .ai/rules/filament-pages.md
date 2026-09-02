---
paths:
  - 'app/Filament/Pages/*.php'
  - app/Filament/Pages/Profile.php
---

# Filament Pages

## Filament v5 Page::$view is a non-static instance property
Declare `protected string $view = '...';` on custom Filament\Pages\Page subclasses — NOT `protected static string $view`. The base class declares it non-static; redeclaring it static is a fatal "Cannot redeclare non static property as static" error that only surfaces when the panel boots (e.g. on `route:list` or first page load), not at edit time. All other page metadata (`$navigationIcon`, `$navigationGroup`, `$navigationLabel`, `$title`) stays `protected static`.

## EditProfile field defaults must come via mutateFormDataBeforeFill, not ->default()
Filament's EditProfile::fillForm() calls $this->getUser()->attributesToArray() then mutateFormDataBeforeFill($data), then $this->form->fill($data) — it never triggers a field's ->default() closure for keys missing from that array. Any read-only/derived field added to Profile's form (e.g. Employee Details section sourced from the linked Employee record) must have its value injected into $data inside an overridden mutateFormDataBeforeFill(), not via ->default(). Fields not backed by a User column (photo, employee_code_display, etc.) must also be stripped out in mutateFormDataBeforeSave() before returning $data, since save() does $this->getUser()->update($data) directly and an unknown column throws.
