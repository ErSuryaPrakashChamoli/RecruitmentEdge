{{-- Slot picker shared by booking and rescheduling. $action is a signed URL; $submit the button label. --}}
@if ($slots->isEmpty())
    <p class="text-sm text-ink-muted">There are no open slots right now. Please check back later or contact your recruiter.</p>
@else
    <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
        @csrf
        <fieldset class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <legend class="sr-only">Available slots</legend>
            @foreach ($slots as $slot)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-line p-3 text-sm has-checked:border-brand has-checked:bg-brand-soft">
                    <input type="radio" name="slot" value="{{ $slot->public_id }}" required class="mt-1">
                    <span>
                        <span class="block font-medium">{{ $slot->localStart()->format('D, d M Y') }}</span>
                        <span class="block">{{ $slot->localStart()->format('h:i A') }} – {{ $slot->localEnd()->format('h:i A') }} <span class="text-ink-muted">({{ $slot->timezone }})</span></span>
                        <span class="block text-ink-muted">{{ $slot->mode->label() }}{{ $slot->interviewer ? ' · with '.$slot->interviewer->fullName() : '' }}</span>
                    </span>
                </label>
            @endforeach
        </fieldset>
        {{ $extra ?? '' }}
        <button type="submit" class="self-start rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">{{ $submit }}</button>
    </form>
@endif
