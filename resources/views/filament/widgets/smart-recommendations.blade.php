<x-filament-widgets::widget>
    <x-filament::section heading="Smart Recommendations" description="AI narration over the database facts already shown on this dashboard — never a source of numbers on its own" collapsible collapsed>
        <x-slot name="afterHeader">
            <x-filament::button size="sm" wire:click="generate" wire:loading.attr="disabled">
                Generate Insights
            </x-filament::button>
        </x-slot>

        <div wire:loading wire:target="generate" class="text-sm text-gray-500 dark:text-gray-400">
            Analyzing current recruitment data&hellip;
        </div>

        @if ($result === null)
            <p wire:loading.remove wire:target="generate" class="text-sm text-gray-500 dark:text-gray-400">
                Click "Generate Insights" to get a prioritized summary of what happened, what needs attention, and what to work on next.
            </p>
        @else
            <div wire:loading.remove wire:target="generate" class="space-y-4">
                @if ($result['narrative'] !== null)
                    <div class="rounded-lg border border-gray-100 dark:border-white/5 p-3">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">AI Recommendation</p>
                        <div class="prose prose-sm dark:prose-invert max-w-none">{!! nl2br(e($result['narrative'])) !!}</div>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">AI narration is not configured. Showing database facts only.</p>
                @endif

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Database Facts</p>
                    <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($result['facts']['alerts'] ?? [] as $alert)
                            <li>&bull; {{ $alert }}</li>
                        @endforeach
                        @foreach ($result['facts']['pending_work'] ?? [] as $item)
                            <li>&bull; {{ $item['label'] }}: {{ $item['count'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
