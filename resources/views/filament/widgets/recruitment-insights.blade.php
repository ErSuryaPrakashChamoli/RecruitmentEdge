<x-filament-widgets::widget>
    <x-filament::section heading="Recruitment Insights" description="Generated from real recruitment data — never hard-coded" collapsible collapsed>
        @php $insights = $this->getInsights(); @endphp

        @if ($insights->isEmpty())
            <x-recruitment.empty-state
                icon="heroicon-o-light-bulb"
                heading="Nothing notable right now"
                description="No thresholds have been crossed in the current period."
            />
        @else
            <div class="space-y-2">
                @foreach ($insights as $insight)
                    <div @class([
                        'flex items-start gap-3 rounded-lg border-s-2 bg-gray-50/60 px-3 py-2.5 dark:bg-white/5',
                        'border-rose-500' => $insight['severity'] === 'critical',
                        'border-amber-500' => $insight['severity'] !== 'critical',
                    ])>
                        <x-filament::icon
                            icon="heroicon-o-light-bulb"
                            @class([
                                'h-4 w-4 shrink-0 translate-y-0.5',
                                'text-rose-500' => $insight['severity'] === 'critical',
                                'text-amber-500' => $insight['severity'] !== 'critical',
                            ])
                        />
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $insight['message'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
