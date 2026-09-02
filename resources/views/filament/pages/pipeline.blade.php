<x-filament-panels::page>
    {{-- Summary --}}
    @php $summary = $this->getSummary(); @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-recruitment.kpi-card label="Open Positions" :value="$summary['open_positions']" color="info" tint />
        <x-recruitment.kpi-card label="Total Candidates" :value="$summary['total_candidates']" />
        <x-recruitment.kpi-card label="In Pipeline" :value="$summary['in_pipeline']" color="info" tint />
        <x-recruitment.kpi-card label="Interviews Today" :value="$summary['interviews_today']" color="warning" tint />
        <x-recruitment.kpi-card label="Offers Pending" :value="$summary['offers_pending']" color="warning" tint />
        <x-recruitment.kpi-card label="Joining This Week" :value="$summary['joining_this_week']" color="success" tint />
    </div>

    {{-- Filters --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <select wire:model.live="requisitionId" class="fi-select-input block rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            <option value="">All Requisitions</option>
            @foreach ($this->requisitionOptions() as $option)
                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
        <select wire:model.live="recruiterId" class="fi-select-input block rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            <option value="">All Recruiters</option>
            @foreach ($this->recruiterOptions() as $option)
                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
            @endforeach
        </select>
        <select wire:model.live="priorityFilter" class="fi-select-input block rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            <option value="">All Priorities</option>
            @foreach (\App\Enums\Priority::cases() as $priority)
                <option value="{{ $priority->value }}">{{ $priority->label() }}</option>
            @endforeach
        </select>
        @if ($requisitionId || $recruiterId || $priorityFilter)
            <button type="button" wire:click="$set('requisitionId', null); $set('recruiterId', null); $set('priorityFilter', null)" class="text-xs font-medium text-gray-500 hover:underline dark:text-gray-400">
                Clear filters
            </button>
        @endif
    </div>

    {{-- Pipeline Intelligence --}}
    @php $intelligence = $this->getIntelligence(); @endphp
    @if ($intelligence->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($intelligence as $insight)
                <span @class([
                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs',
                    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' => $insight['severity'] === 'critical',
                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => $insight['severity'] !== 'critical',
                ])>
                    <x-filament::icon icon="heroicon-o-light-bulb" class="h-3.5 w-3.5" />
                    {{ $insight['message'] }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="mt-4 flex gap-4 overflow-x-auto pb-4">
        @foreach ($this->getColumns() as $column)
            @php $data = $this->getCardsFor($column['stages']); @endphp
            <div class="flex w-72 shrink-0 flex-col rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                <div class="border-b border-gray-200 px-3 py-2 dark:border-white/10">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $column['label'] }}</span>
                        <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                            {{ $data['total'] }}
                        </span>
                    </div>
                    @if ($data['conversion'] !== null)
                        <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">{{ $data['conversion'] }}% conversion</p>
                    @endif
                </div>

                @if ($column['dragStage'] !== null)
                    <div wire:sort="handleSort" wire:sort:group="pipeline-cards" wire:sort:group-id="{{ $column['key'] }}" class="flex flex-col gap-2 p-2">
                        @forelse ($data['applications'] as $application)
                            <div wire:key="card-{{ $application->id }}" wire:sort:item="{{ $application->id }}">
                                @include('filament.pages.pipeline-card', ['application' => $application])
                            </div>
                        @empty
                            <p class="px-1 py-4 text-center text-xs text-gray-400 dark:text-gray-500">No candidates here</p>
                        @endforelse
                    </div>
                @else
                    <div class="flex flex-col gap-2 p-2">
                        @forelse ($data['applications'] as $application)
                            @include('filament.pages.pipeline-card', ['application' => $application])
                        @empty
                            <p class="px-1 py-4 text-center text-xs text-gray-400 dark:text-gray-500">No candidates here</p>
                        @endforelse
                    </div>
                @endif

                <div class="px-2 pb-2">
                    @if ($data['total'] > count($data['applications']))
                        <a
                            href="{{ $this->getColumnListUrl($column['key']) }}"
                            class="block rounded-lg py-1 text-center text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            View all {{ $data['total'] }} in list
                        </a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
