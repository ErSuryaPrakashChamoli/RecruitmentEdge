<x-mail::message>
# {{ $event?->title ?? 'Platform event' }}

**Severity:** {{ $event?->severity->value }}  
**Type:** {{ $event?->type }}  
@if ($event?->tenant)
**Tenant:** {{ $event->tenant->slug }}  
@endif
**When:** {{ $event?->occurred_at?->toDayDateTimeString() }}

Open the platform panel's Operational events to see and acknowledge it.

{{ \App\Services\Branding::platformName() }}
</x-mail::message>
