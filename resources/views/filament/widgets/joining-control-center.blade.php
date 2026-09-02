<x-filament-widgets::widget>
    <div class="space-y-6">
        {{-- Summary --}}
        @php $summary = $this->getSummary(); @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            <x-recruitment.kpi-card label="Joining Today" :value="$summary['today']" color="info" tint />
            <x-recruitment.kpi-card label="Joining Tomorrow" :value="$summary['tomorrow']" color="info" tint />
            <x-recruitment.kpi-card label="This Week" :value="$summary['this_week']" />
            <x-recruitment.kpi-card label="Confirmed" :value="$summary['confirmed']" color="success" tint />
            <x-recruitment.kpi-card label="Needs Follow-up" :value="$summary['needs_followup']" color="warning" tint />
            <x-recruitment.kpi-card label="High Risk" :value="$summary['high_risk']" color="danger" tint />
            <x-recruitment.kpi-card label="No Show" :value="$summary['no_show']" color="danger" tint />
            <x-recruitment.kpi-card label="Joined" :value="$summary['joined']" color="success" tint />
        </div>

        {{-- Pipeline --}}
        <x-filament::section heading="Joining Pipeline" description="Selected through to Joined, this month">
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($this->getPipeline() as $index => $step)
                    <div class="flex items-center gap-2">
                        <div class="rounded-lg border border-gray-200 px-3 py-2 text-center dark:border-white/10">
                            <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $step['count'] }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $step['label'] }}</p>
                        </div>
                        @if (! $loop->last)
                            <x-filament::icon icon="heroicon-o-arrow-right" class="h-3.5 w-3.5 text-gray-300 dark:text-gray-600" />
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Risk panel --}}
        <x-filament::section heading="Joining Risk">
            @php $risk = $this->getRiskGroups(); @endphp
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach ([['key' => 'green', 'label' => 'Confirmed', 'color' => 'emerald'], ['key' => 'yellow', 'label' => 'Follow-up Required', 'color' => 'amber'], ['key' => 'red', 'label' => 'High Risk', 'color' => 'rose']] as $group)
                    <div>
                        <p @class([
                            'mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide',
                            'text-emerald-600 dark:text-emerald-400' => $group['color'] === 'emerald',
                            'text-amber-600 dark:text-amber-400' => $group['color'] === 'amber',
                            'text-rose-600 dark:text-rose-400' => $group['color'] === 'rose',
                        ])>
                            <span @class([
                                'h-2 w-2 rounded-full',
                                'bg-emerald-500' => $group['color'] === 'emerald',
                                'bg-amber-500' => $group['color'] === 'amber',
                                'bg-rose-500' => $group['color'] === 'rose',
                            ])></span>
                            {{ $group['label'] }} ({{ $risk[$group['key']]->count() }})
                        </p>
                        <div class="space-y-1.5">
                            @forelse ($risk[$group['key']] as $joining)
                                <a href="{{ $this->candidateUrl($joining) }}" class="block rounded-lg border border-gray-100 px-2.5 py-1.5 text-xs hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $joining->candidateApplication->candidate->full_name }}</span>
                                    <span class="block text-gray-400 dark:text-gray-500">Expected {{ $joining->expected_doj->format('d M Y') }}</span>
                                </a>
                            @empty
                                <p class="text-xs text-gray-400 dark:text-gray-500">None</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Joining tomorrow --}}
        <x-filament::section heading="Joining Tomorrow" description="Act now — call, message, or check in before their start date">
            @php $tomorrow = $this->getTomorrowJoinings(); @endphp

            @if ($tomorrow->isEmpty())
                <x-recruitment.empty-state
                    icon="heroicon-o-user-plus"
                    heading="No joiners tomorrow"
                    description="Nothing scheduled to join tomorrow for your visible team."
                />
            @else
                <div class="space-y-2">
                    @foreach ($tomorrow as $row)
                        @php
                            $joining = $row['joining'];
                            $candidate = $joining->candidateApplication->candidate;
                            $riskLevel = $joining->riskLevel();
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2 dark:border-white/5">
                            <div class="flex items-center gap-2">
                                <x-recruitment.avatar-initials :name="$candidate->full_name" />
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $candidate->full_name }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $joining->candidateApplication->requisition?->designation?->name ?? '—' }} &middot; {{ $joining->candidateApplication->recruiter->fullName() }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ $joining->confirmed_at ? 'Confirmed' : 'Not confirmed' }}</span>
                                <span>Last contact: {{ $row['lastContact']?->diffForHumans() ?? '—' }}</span>
                                <x-filament::badge :color="$riskLevel === 'green' ? 'success' : ($riskLevel === 'yellow' ? 'warning' : 'danger')" size="xs">
                                    {{ $riskLevel === 'green' ? 'On Track' : ($riskLevel === 'yellow' ? 'Follow-up' : 'High Risk') }}
                                </x-filament::badge>
                            </div>

                            <div class="flex items-center gap-1">
                                @if ($candidate->mobile)
                                    <x-filament::icon-button icon="heroicon-o-phone" label="Call" tooltip="Call" tag="a" :href="'tel:'.$candidate->mobile" />
                                    <x-filament::icon-button
                                        icon="heroicon-o-chat-bubble-left-right"
                                        label="WhatsApp"
                                        tooltip="WhatsApp"
                                        tag="a"
                                        :href="'https://wa.me/'.preg_replace('/\D/', '', $candidate->mobile)"
                                        target="_blank"
                                    />
                                @endif
                                <x-filament::icon-button icon="heroicon-o-eye" label="View Candidate" tooltip="View Candidate" tag="a" :href="$this->candidateUrl($joining)" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
