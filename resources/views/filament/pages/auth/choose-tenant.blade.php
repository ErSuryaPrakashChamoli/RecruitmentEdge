<x-filament-panels::page.simple>
    <ul class="flex flex-col gap-3">
        @foreach ($this->tenants() as $tenant)
            <li class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ $tenant->name }}</p>
                    @if ($this->defaultTenantId() === (int) $tenant->getKey())
                        <p class="text-xs text-gray-500 dark:text-gray-400">Your default</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($this->defaultTenantId() !== (int) $tenant->getKey())
                        <x-filament::button color="gray" size="sm" wire:click="makeDefault({{ (int) $tenant->getKey() }})">
                            Make default
                        </x-filament::button>
                    @endif
                    <x-filament::button tag="a" size="sm" :href="$this->urlFor($tenant)">
                        Open
                    </x-filament::button>
                </div>
            </li>
        @endforeach
    </ul>
</x-filament-panels::page.simple>
