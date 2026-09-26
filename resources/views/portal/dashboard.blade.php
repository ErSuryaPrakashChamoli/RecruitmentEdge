<x-portal.layout title="Your applications">
    <div>
        <h1 class="text-2xl font-semibold">Hello, {{ $account->candidate->full_name }}</h1>
        <p class="text-sm text-ink-muted">Here is where your applications stand.</p>
    </div>

    @if ($invitations->isNotEmpty())
        <x-portal.card title="Choose your interview time" description="Your recruiter has invited you to pick an interview slot.">
            <ul class="flex flex-col gap-3">
                @foreach ($invitations as $invitation)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-soft px-4 py-3">
                        <div>
                            <p class="font-medium">{{ $invitation->candidateApplication->requisition?->designation?->name ?? 'Interview' }}</p>
                            <p class="text-xs text-ink-muted">Pick a slot before {{ $invitation->expires_at->format('d M Y') }}</p>
                        </div>
                        <a href="{{ route('portal.schedule.show', $invitation->public_id) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Choose a slot</a>
                    </li>
                @endforeach
            </ul>
        </x-portal.card>
    @endif

    <x-portal.card title="Your applications">
        @forelse ($applications as $application)
            <a href="{{ route('portal.applications.show', $application->application_code) }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3 last:border-b-0 hover:bg-surface-muted">
                <div>
                    <p class="font-medium">{{ $application->requisition?->designation?->name ?? 'Position' }}</p>
                    <p class="text-xs text-ink-muted">{{ $application->requisition?->location?->name }} · Ref {{ $application->application_code }}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm font-medium">{{ $portal->stageLabel($application) }}</p>
                    <p class="text-xs text-ink-muted">{{ $portal->statusLabel($application) }}</p>
                </div>
            </a>
        @empty
            <p class="text-sm text-ink-muted">You have no applications yet.</p>
        @endforelse
    </x-portal.card>

    @if ($messages->isNotEmpty())
        <x-portal.card title="Messages from us" description="Manage how we contact you in your profile.">
            @foreach ($messages as $message)
                <details class="border-b border-line py-2 text-sm last:border-b-0">
                    <summary class="flex cursor-pointer items-start justify-between gap-4">
                        <span><span class="text-ink-muted">{{ $message->channel->label() }}:</span> {{ $message->subject ?? \Illuminate\Support\Str::limit($message->body, 70) }}</span>
                        <time class="shrink-0 text-xs text-ink-muted" datetime="{{ $message->sent_at?->toIso8601String() }}">{{ $message->sent_at?->format('d M Y') }}</time>
                    </summary>
                    <p class="mt-2 whitespace-pre-line text-ink-muted">{{ $message->body }}</p>
                </details>
            @endforeach
        </x-portal.card>
    @endif

    <x-portal.card title="Recent updates">
        @forelse ($timeline as $entry)
            <div class="flex items-start justify-between gap-4 border-b border-line py-2 text-sm last:border-b-0">
                <div>
                    <p>{{ $entry['title'] }}</p>
                    @if ($entry['description'])
                        <p class="text-xs text-ink-muted">{{ $entry['description'] }}</p>
                    @endif
                </div>
                <time class="shrink-0 text-xs text-ink-muted" datetime="{{ $entry['at']->toIso8601String() }}">{{ $entry['at']->format('d M Y') }}</time>
            </div>
        @empty
            <p class="text-sm text-ink-muted">No updates yet.</p>
        @endforelse
    </x-portal.card>
</x-portal.layout>
