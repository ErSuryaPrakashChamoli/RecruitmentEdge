<x-filament-widgets::widget>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
        @foreach ($this->getCards() as $card)
            <x-recruitment.kpi-stat
                :label="$card['label']"
                :value="$card['value']"
                :icon="$card['icon']"
                :color="$card['color']"
                :trend="$card['trend']"
                :trend-label="$card['trendLabel']"
                :sparkline="$card['sparkline'] ?? null"
                :progress="$card['progress'] ?? null"
            />
        @endforeach
    </div>
</x-filament-widgets::widget>
