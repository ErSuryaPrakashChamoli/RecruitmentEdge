<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Recruiter scorecard --}}
        @php $scorecard = $this->getMyScorecard(); @endphp
        <x-filament::section heading="My Incentive Scorecard" description="Your calculations for the current period">
            @if ($scorecard->isEmpty())
                <x-recruitment.empty-state
                    icon="heroicon-o-banknotes"
                    heading="No incentive calculations yet"
                    description="Nothing has been calculated for you this period."
                />
            @else
                <div class="space-y-4">
                    @foreach ($scorecard as $row)
                        @php
                            $calculation = $row['calculation'];
                            $progress = $row['slabProgress'];
                            $achievementPct = $calculation->achievement !== null ? min(100, max(0, (float) $calculation->achievement)) : null;
                        @endphp
                        <div class="rounded-lg border border-gray-100 p-4 dark:border-white/5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $calculation->incentiveRule?->name ?? 'Incentive' }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $calculation->candidate?->full_name }} &middot; {{ $calculation->period_start->format('M Y') }}</p>
                                </div>
                                <x-filament::badge :color="$calculation->status->color()">{{ $calculation->status->label() }}</x-filament::badge>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">Target</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row['target'] !== null ? number_format($row['target']) : '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">Achievement</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $achievementPct !== null ? number_format($achievementPct, 1).'%' : '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">Base Incentive</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">₹{{ number_format((float) $calculation->amount, 2) }}</p>
                                </div>
                                <div>
                                    <p class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">Final Incentive</p>
                                    <p class="text-sm font-semibold text-emerald-600 dark:text-emerald-400">₹{{ number_format($row['final'], 2) }}</p>
                                </div>
                            </div>

                            @if ($progress)
                                <div class="mt-4">
                                    <div class="flex items-center justify-between text-[11px] text-gray-500 dark:text-gray-400">
                                        <span>Slab: {{ number_format((float) $progress['current']->achievement_min, 1) }}%{{ $progress['current']->achievement_max !== null ? ' – '.number_format((float) $progress['current']->achievement_max, 1).'%' : '+' }} &middot; ₹{{ number_format((float) $progress['current']->amount, 2) }}</span>
                                        @if ($progress['next'])
                                            <span>Next slab at {{ number_format((float) $progress['next']->achievement_min, 1) }}%</span>
                                        @endif
                                    </div>
                                    <span class="mt-1 block h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                                        <span class="block h-full rounded-full bg-primary-500" style="width: {{ $progress['progressPct'] }}%"></span>
                                    </span>
                                    @if ($progress['next'])
                                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ number_format($progress['remaining'], 1) }}% more to reach the next slab
                                            @if ($progress['potentialAdditional'] !== null)
                                                — potential additional ₹{{ number_format($progress['potentialAdditional'], 2) }}
                                            @endif
                                        </p>
                                    @else
                                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Top slab reached.</p>
                                    @endif
                                </div>
                            @endif

                            <details class="mt-3 group">
                                <summary class="cursor-pointer text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">Breakdown</summary>
                                <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg bg-gray-50 p-3 text-xs dark:bg-white/5 sm:grid-cols-3">
                                    <div><span class="text-gray-400 dark:text-gray-500">Calculated:</span> ₹{{ number_format((float) $calculation->amount, 2) }}</div>
                                    <div><span class="text-gray-400 dark:text-gray-500">Adjustments:</span> ₹{{ number_format($row['adjustmentsTotal'], 2) }}</div>
                                    <div><span class="text-gray-400 dark:text-gray-500">Final:</span> ₹{{ number_format($row['final'], 2) }}</div>
                                    <div><span class="text-gray-400 dark:text-gray-500">Retention due:</span> {{ $calculation->retention_due_at?->format('d M Y') ?? '—' }}</div>
                                    <div class="col-span-2 sm:col-span-1">
                                        <a href="{{ $this->calculationUrl($calculation) }}" class="text-primary-600 hover:underline dark:text-primary-400">View full statement →</a>
                                    </div>
                                </div>
                            </details>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Team incentive --}}
        @if ($this->isTeamView())
            @php
                $team = $this->getTeamIncentives();
                $topPerformer = $team->where('amount', '>', 0)->sortByDesc('amount')->first();
                $needsAttention = $team->sortBy('amount')->first(fn ($row) => $row['amount'] <= 0 || $row['status'] === 'No Activity');
                $highestGrowth = $team->where('growth', '>', 0)->sortByDesc('growth')->first();
            @endphp
            <x-filament::section heading="Team Incentive" description="Current period, scoped to your visible team">
                @if ($team->isEmpty())
                    <x-recruitment.empty-state
                        icon="heroicon-o-user-group"
                        heading="No team incentive activity"
                        description="Nothing calculated for your team this period."
                    />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                    <th class="pb-2 pr-4 font-medium">Recruiter</th>
                                    <th class="pb-2 pr-4 font-medium">Achievement</th>
                                    <th class="pb-2 pr-4 font-medium">Incentive</th>
                                    <th class="pb-2 pr-4 font-medium">Status</th>
                                    <th class="pb-2 font-medium">Badge</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($team as $row)
                                    <tr>
                                        <td class="py-2 pr-4">
                                            <div class="flex items-center gap-2">
                                                <x-recruitment.avatar-initials :name="$row['recruiter']->fullName()" />
                                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $row['recruiter']->fullName() }}</span>
                                            </div>
                                        </td>
                                        <td class="py-2 pr-4 tabular-nums text-gray-600 dark:text-gray-400">{{ $row['achievement'] !== null ? number_format($row['achievement'], 1).'%' : '—' }}</td>
                                        <td class="py-2 pr-4 tabular-nums font-medium text-gray-900 dark:text-gray-100">₹{{ number_format($row['amount'], 2) }}</td>
                                        <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">{{ $row['status'] }}</td>
                                        <td class="py-2">
                                            <div class="flex flex-wrap gap-1">
                                                @if ($topPerformer && $row['recruiter']->id === $topPerformer['recruiter']->id)
                                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Top Performer</span>
                                                @endif
                                                @if ($highestGrowth && $row['recruiter']->id === $highestGrowth['recruiter']->id)
                                                    <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-700 dark:bg-blue-500/10 dark:text-blue-400">Highest Growth</span>
                                                @endif
                                                @if ($needsAttention && $row['recruiter']->id === $needsAttention['recruiter']->id)
                                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Needs Attention</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
