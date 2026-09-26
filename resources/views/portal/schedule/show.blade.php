<x-portal.layout title="Choose your interview time">
    <div>
        <h1 class="text-2xl font-semibold">Choose your interview time</h1>
        <p class="text-sm text-ink-muted">{{ $application->requisition?->designation?->name ?? 'Interview' }} · Ref {{ $application->application_code }}</p>
    </div>

    @if ($booking)
        <x-portal.card title="You're booked">
            <p class="text-sm">Your interview is on <span class="font-medium">{{ $booking->slot->displayWindow() }}</span>.</p>
            <a href="{{ URL::temporarySignedRoute('portal.bookings.show', now()->addDays(14), ['booking' => $booking->public_id]) }}" class="mt-3 inline-block text-sm text-brand hover:underline">Change or cancel this booking</a>
        </x-portal.card>
    @elseif (! $invitation->isOpen())
        <x-portal.card title="This link is no longer active">
            <p class="text-sm text-ink-muted">The invitation has expired or was already used. Please contact your recruiter for a new one.</p>
        </x-portal.card>
    @else
        <x-portal.card title="Available slots" description="Times are shown in the interviewer's timezone.">
            @include('portal.schedule.slot-list', ['action' => $bookUrl, 'submit' => 'Book this slot'])
        </x-portal.card>
    @endif
</x-portal.layout>
