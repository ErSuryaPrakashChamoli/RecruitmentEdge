---
paths:
  - 'resources/views/filament/**'
---

# Views Filament

## Never name a Blade loop variable $component
Inside a Blade view, any <x-...> component tag (e.g. x-filament::badge) overwrites $component with the anonymous component instance, so `@foreach ($items as $component)` breaks on the next iteration ("Cannot use object of type AnonymousComponent as array"). Use another name such as $item.

## Guard actions echoed in custom Blade with isVisible()
Filament v5 renders a hidden action as a disabled button when you echo it yourself (`{{ $this->fooAction }}` or `{{ ($this->fooAction)([...]) }}` in a custom page view) — Action::isDisabled() returns true when hidden, and toHtml() still outputs the button. Header/table actions are filtered for you; custom views are not. Wrap each one: `@if ($this->fooAction->isVisible()) {{ $this->fooAction }} @endif`. Server-side authorization still applies, but unauthorized users would otherwise see greyed-out controls.
