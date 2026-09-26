<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        <strong>Implemented</strong> means the adapter exists. <strong>Configured</strong> means its credentials are set in the environment.
        <strong>Operational</strong> is only shown after a successful connection test — configuration alone never counts.
    </p>

    @foreach ($this->getIntegrations() as $category => $integrations)
        <x-filament::section :heading="\Illuminate\Support\Str::of($category)->replace('_', ' ')->title()" class="mt-4">
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($integrations as $integration)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $integration['label'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $integration['key'] }}
                                @if ($integration['last_tested_at'])
                                    &middot; tested {{ $integration['last_tested_at']->diffForHumans() }}: {{ $integration['last_test_message'] }}
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::badge color="gray">Implemented</x-filament::badge>
                            <x-filament::badge :color="$integration['configured'] ? 'info' : 'gray'">{{ $integration['configured'] ? 'Configured' : 'Not configured' }}</x-filament::badge>
                            <x-filament::badge :color="match ($integration['operational']) { true => 'success', false => 'danger', default => 'gray' }">
                                {{ match ($integration['operational']) { true => 'Operational', false => 'Test failed', default => 'Not tested' } }}
                            </x-filament::badge>
                            <x-filament::button size="xs" color="gray" wire:click="mountAction('testIntegration', { key: '{{ $integration['key'] }}' })">Test</x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach

    <x-filament-actions::modals />
</x-filament-panels::page>
