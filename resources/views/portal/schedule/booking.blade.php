<x-portal.layout title="Your interview booking">
    <div>
        <h1 class="text-2xl font-semibold">Your interview</h1>
        <p class="text-sm text-ink-muted">{{ $booking->candidateApplication->requisition?->designation?->name ?? 'Interview' }} · Ref {{ $booking->candidateApplication->application_code }}</p>
    </div>

    <x-portal.card title="{{ $booking->isActive() ? 'Booked' : $booking->status->label() }}">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-muted">When</dt><dd class="font-medium">{{ $booking->slot->displayWindow() }}</dd></div>
            <div><dt class="text-ink-muted">How</dt><dd class="font-medium">{{ $booking->slot->mode->label() }}</dd></div>
            <div><dt class="text-ink-muted">With</dt><dd class="font-medium">{{ $booking->slot->interviewer?->fullName() ?? '—' }}</dd></div>
        </dl>
        @if ($booking->isActive() && $booking->slot->meeting_link)
            <a href="{{ $booking->slot->meeting_link }}" rel="noopener noreferrer" target="_blank" class="mt-3 inline-block text-sm text-brand hover:underline">Join link</a>
        @endif
    </x-portal.card>

    @if ($booking->isActive())
        <x-portal.card title="Need a different time?">
            @include('portal.schedule.slot-list', ['action' => $rescheduleUrl, 'submit' => 'Move my interview', 'extra' => new \Illuminate\Support\HtmlString('<input type="text" name="reason" maxlength="500" placeholder="Reason (optional)" class="'.trim(view('portal.partials.input-class')->render()).'">')])
        </x-portal.card>

        <x-portal.card title="Cancel this booking">
            <form method="POST" action="{{ $cancelUrl }}" class="flex flex-col gap-3">
                @csrf
                <textarea name="reason" rows="2" required minlength="3" maxlength="500" placeholder="Let your recruiter know why" class="@include('portal.partials.input-class')"></textarea>
                <button type="submit" class="self-start rounded-lg border border-line px-4 py-2 text-sm font-medium text-danger hover:bg-danger-soft">Cancel booking</button>
            </form>
        </x-portal.card>
    @endif
</x-portal.layout>
