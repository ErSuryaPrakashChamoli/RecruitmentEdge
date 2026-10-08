<x-portal.layout :title="$application->requisition?->designation?->name">
    <div>
        <a href="{{ route('portal.dashboard') }}" class="text-sm text-brand hover:underline">← All applications</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $application->requisition?->designation?->name ?? 'Position' }}</h1>
        <p class="text-sm text-ink-muted">{{ $application->requisition?->location?->name }} · Ref {{ $application->application_code }}</p>
    </div>

    <x-portal.card title="Status">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-muted">Current stage</dt><dd class="font-medium">{{ $portal->stageLabel($application) }}</dd></div>
            <div><dt class="text-ink-muted">Status</dt><dd class="font-medium">{{ $portal->statusLabel($application) }}</dd></div>
            <div><dt class="text-ink-muted">Applied</dt><dd class="font-medium">{{ $application->application_date?->format('d M Y') ?? $application->created_at->format('d M Y') }}</dd></div>
        </dl>
    </x-portal.card>

    <x-portal.card title="Interviews">
        @forelse ($application->interviews as $interview)
            <div class="flex flex-col gap-3 border-b border-line py-4 first:pt-0 last:border-b-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-medium">Round {{ $interview->round_number }}{{ $interview->round_name ? ' · '.$interview->round_name : '' }}</p>
                        <p class="text-sm text-ink-muted">{{ $interview->scheduled_at?->format('D, d M Y · h:i A') }} · {{ $interview->mode->label() }}</p>
                        @if ($interview->meeting_link && ! $interview->status->isTerminal())
                            <a href="{{ $interview->meeting_link }}" rel="noopener noreferrer" target="_blank" class="text-sm text-brand hover:underline">Join link</a>
                        @elseif ($interview->location && ! $interview->status->isTerminal())
                            <p class="text-sm text-ink-muted">{{ $interview->location }}</p>
                        @endif
                    </div>
                    <span @class(['rounded-full px-2.5 py-0.5 text-xs font-medium',
                        'bg-success-soft text-success' => in_array($interview->status->value, ['confirmed', 'completed'], true),
                        'bg-warning-soft text-warning' => in_array($interview->status->value, ['scheduled', 'rescheduled', 'hold'], true),
                        'bg-surface-muted text-ink-muted' => in_array($interview->status->value, ['cancelled', 'no_show', 'pending'], true),
                    ])>{{ $interview->status->label() }}</span>
                </div>

                @if ($interview->status->awaitsConfirmation())
                    <div class="flex flex-wrap items-start gap-3">
                        <form method="POST" action="{{ route('portal.interviews.confirm', [$application->application_code, $interview->round_number]) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Confirm I'll attend</button>
                        </form>
                        @if ($booking = $bookings->get($application->id))
                            <a href="{{ route('portal.bookings.show', $booking->public_id) }}" class="rounded-lg border border-line px-4 py-2 text-sm font-medium hover:bg-surface-muted">Pick another time</a>
                        @else
                            <details class="w-full sm:w-auto">
                                <summary class="cursor-pointer rounded-lg border border-line px-4 py-2 text-sm font-medium hover:bg-surface-muted">Ask for another time</summary>
                                <form method="POST" action="{{ route('portal.interviews.reschedule-request', [$application->application_code, $interview->round_number]) }}" class="mt-3 flex flex-col gap-2">
                                    @csrf
                                    <textarea name="reason" rows="2" required minlength="5" maxlength="1000" placeholder="Tell your recruiter which times work for you" class="@include('portal.partials.input-class')"></textarea>
                                    <button type="submit" class="self-start rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Send request</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-ink-muted">No interviews scheduled yet.</p>
        @endforelse
    </x-portal.card>
</x-portal.layout>
