<x-portal.layout title="Your profile">
    <x-portal.card title="Your profile" description="Your name, mobile and email are managed by your recruiter — contact them to change those.">
        <dl class="mb-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-muted">Name</dt><dd class="font-medium">{{ $candidate->full_name }}</dd></div>
            <div><dt class="text-ink-muted">Mobile</dt><dd class="font-medium">{{ $candidate->mobile }}</dd></div>
            <div><dt class="text-ink-muted">Email</dt><dd class="font-medium">{{ $candidate->email }}</dd></div>
        </dl>

        <form method="POST" action="{{ route('portal.profile.update') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PUT')
            @foreach (['alternate_mobile' => 'Alternate mobile', 'current_city' => 'Current city', 'location' => 'Preferred location', 'current_company' => 'Current company', 'current_designation' => 'Current designation'] as $field => $label)
                <label class="flex flex-col gap-1 text-sm font-medium">
                    {{ $label }}
                    <input type="text" name="{{ $field }}" value="{{ old($field, $candidate->{$field}) }}" maxlength="255" class="@include('portal.partials.input-class')">
                </label>
            @endforeach
            <label class="flex flex-col gap-1 text-sm font-medium">
                Notice period (days)
                <input type="number" name="notice_period_days" min="0" max="365" value="{{ old('notice_period_days', $candidate->notice_period_days) }}" class="@include('portal.partials.input-class')">
            </label>

            <fieldset class="flex flex-col gap-2 text-sm sm:col-span-2">
                <legend class="mb-1 font-medium">Contact me about my applications by</legend>
                <p class="text-xs text-ink-muted">Untick a channel to stop messages on it. WhatsApp messages are only sent if you tick it.</p>
                @foreach (\App\Models\CandidatePortalAccount::COMMUNICATION_CHANNELS as $channel)
                    <input type="hidden" name="communication_preferences[{{ $channel }}]" value="0">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="communication_preferences[{{ $channel }}]" value="1" @checked($account->wantsChannel($channel)) class="rounded border-line">
                        {{ \App\Enums\CommunicationChannel::from($channel)->label() }}
                    </label>
                @endforeach
            </fieldset>

            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Save changes</button>
            </div>
        </form>
    </x-portal.card>
</x-portal.layout>
