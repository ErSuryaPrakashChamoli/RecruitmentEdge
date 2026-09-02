<x-filament-panels::page>
    @php $record = $this->getRecord(); @endphp

    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/5 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <x-recruitment.avatar-initials :name="$record->full_name" size="md" />
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $record->full_name }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $record->candidate_code }} &middot; Source: {{ $record->source?->name ?? '—' }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @if ($record->mobile)
                    <x-filament::icon-button icon="heroicon-o-phone" label="Call" tooltip="Call" tag="a" :href="'tel:'.$record->mobile" />
                    <x-filament::icon-button
                        icon="heroicon-o-chat-bubble-left-right"
                        label="WhatsApp"
                        tooltip="WhatsApp"
                        tag="a"
                        :href="'https://wa.me/'.preg_replace('/\D/', '', $record->mobile)"
                        target="_blank"
                    />
                @endif
                @if ($record->email)
                    <x-filament::icon-button icon="heroicon-o-envelope" label="Email" tooltip="Email" tag="a" :href="'mailto:'.$record->email" />
                @endif
            </div>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->content }}
    </div>
</x-filament-panels::page>
