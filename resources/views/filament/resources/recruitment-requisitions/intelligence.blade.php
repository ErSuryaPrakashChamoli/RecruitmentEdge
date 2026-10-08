<x-filament-panels::page>
    @php
        $requisition = $this->requisition();
        $health = $this->health();
        $risks = $this->risks();
        $profile = $this->profile();
        $dna = $profile?->currentVersion;
        $suggestions = $dna?->pendingSuggestions() ?? collect();
        $signals = $this->signals();
        $run = $this->latestRun();
        $memory = $this->memory();
        $statusColor = fn (string $status) => ['ok' => 'success', 'watch' => 'info', 'breach' => 'danger', 'unknown' => 'gray'][$status] ?? 'gray';
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-300">
        Facts, signals and risks below are calculated from recorded data by named rules; AI only ever adds clearly-marked, unconfirmed suggestions. Every value has a <strong>Why?</strong> link to its evidence. Decisions stay with people.
    </p>

    {{-- Hiring Health --}}
    <x-filament::section>
        <x-slot name="heading">
            <span class="flex flex-wrap items-center gap-2">
                Hiring Health
                @if ($health)
                    <x-filament::badge :color="$health->status->color()">{{ $health->status->label() }}</x-filament::badge>
                @endif
            </span>
        </x-slot>
        <x-slot name="description">
            @if ($health)
                Computed {{ $health->computed_at->diffForHumans() }} · rules {{ $health->rules_version }}@unless ($this->healthIsFresh()) · <span class="text-warning-600 dark:text-warning-400">stale — refresh for current figures</span>@endunless
            @else
                Not computed yet.
            @endif
        </x-slot>
        <x-slot name="afterHeader">@if ($this->refreshHealthAction->isVisible()) {{ $this->refreshHealthAction }} @endif</x-slot>

        @if ($health)
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr><th class="py-2 pr-3 font-medium">Metric</th><th class="py-2 pr-3 font-medium">Current</th><th class="py-2 pr-3 font-medium">Threshold</th><th class="py-2 pr-3 font-medium">Status</th><th class="py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($health->metrics as $metric)
                            <tr class="text-gray-800 dark:text-gray-200">
                                <td class="py-2 pr-3 font-medium text-gray-950 dark:text-white">{{ $metric['label'] }}</td>
                                <td class="py-2 pr-3">{{ $metric['display'] }}</td>
                                <td class="py-2 pr-3 text-gray-500 dark:text-gray-400">{{ $metric['threshold'] }}</td>
                                <td class="py-2 pr-3"><x-filament::badge :color="$statusColor($metric['status'])" size="sm">{{ ucfirst($metric['status']) }}</x-filament::badge></td>
                                <td class="py-2 text-right">@if ($this->evidenceAction->isVisible()) {{ ($this->evidenceAction)(['owner' => 'hiring_health', 'id' => $health->id, 'subject' => $metric['key'], 'heading' => $metric['label']]) }} @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">Refresh to calculate this requisition's health from its pipeline, SLA, offers and joining data.</p>
        @endif
    </x-filament::section>

    {{-- Risks --}}
    <x-filament::section heading="Hiring Risk Radar" :description="$risks->isEmpty() ? 'No open risks.' : $risks->count().' open risk(s). Risks are warnings, never decisions.'">
        @if ($risks->isNotEmpty())
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($risks as $risk)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between" wire:key="risk-{{ $risk->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge :color="$risk->severity->color()" size="sm">{{ $risk->severity->label() }}</x-filament::badge>
                                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $risk->title }}</span>
                                @if ($risk->status->value === 'acknowledged')
                                    <x-filament::badge color="gray" size="sm">Acknowledged</x-filament::badge>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $risk->description }}</p>
                            <p class="mt-1 text-sm text-primary-700 dark:text-primary-300">→ {{ $risk->recommended_action }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">First detected {{ $risk->first_detected_at->diffForHumans() }} · last seen {{ $risk->last_seen_at->diffForHumans() }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($this->evidenceAction->isVisible()) {{ ($this->evidenceAction)(['owner' => 'hiring_risk', 'id' => $risk->id, 'heading' => $risk->title]) }} @endif
                            @if ($risk->status->value === 'open')
                                @if ($this->acknowledgeRiskAction->isVisible()) {{ ($this->acknowledgeRiskAction)(['risk' => $risk->id]) }} @endif
                            @endif
                            @if ($this->dismissRiskAction->isVisible()) {{ ($this->dismissRiskAction)(['risk' => $risk->id]) }} @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    {{-- Role DNA --}}
    <x-filament::section>
        <x-slot name="heading">
            <span class="flex flex-wrap items-center gap-2">
                Role DNA
                @if ($profile && $dna)
                    <x-filament::badge color="gray">v{{ $dna->version }}</x-filament::badge>
                    <x-filament::badge :color="$profile->status->color()">{{ $profile->status->label() }}</x-filament::badge>
                @endif
            </span>
        </x-slot>
        <x-slot name="description">
            @if ($dna)
                {{ $dna->change_summary }} · {{ $dna->created_at->diffForHumans() }}
                @if ($profile->ai_status->value !== 'not_requested')
                    · AI: {{ $profile->ai_status->label() }}@if ($profile->ai_error) ({{ $profile->ai_error }})@endif
                @endif
            @else
                The structured definition of this role — built from the requisition, how interviews are run, and past hires.
            @endif
        </x-slot>
        <x-slot name="afterHeader">
            <div class="flex flex-wrap gap-2">
                @if ($this->buildRoleDnaAction->isVisible()) {{ $this->buildRoleDnaAction }} @endif
                @if ($this->addAttributeAction->isVisible()) {{ $this->addAttributeAction }} @endif
                @if ($this->requestAiSuggestionsAction->isVisible()) {{ $this->requestAiSuggestionsAction }} @endif
                @if ($this->confirmRoleDnaAction->isVisible()) {{ $this->confirmRoleDnaAction }} @endif
            </div>
        </x-slot>

        @if ($dna)
            @if ($suggestions->isNotEmpty())
                <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-500/40 dark:bg-warning-500/10">
                    <p class="text-sm font-medium text-warning-800 dark:text-warning-300">{{ $suggestions->count() }} AI suggestion(s) — not used until a person confirms each one</p>
                    <ul class="mt-2 space-y-2">
                        @foreach ($suggestions as $attribute)
                            <li class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between" wire:key="suggestion-{{ $attribute['key'] }}">
                                <span class="text-sm text-gray-900 dark:text-gray-100">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Enums\RoleDnaCategory::from($attribute['category'])->label() }} ·</span>
                                    {{ $attribute['label'] }}
                                    <span class="text-xs text-gray-500 dark:text-gray-400">({{ $attribute['level'] }})</span>
                                </span>
                                <span class="flex flex-wrap items-center gap-2">
                                    @if ($this->evidenceAction->isVisible()) {{ ($this->evidenceAction)(['owner' => 'role_dna_version', 'id' => $dna->id, 'subject' => $attribute['key'], 'heading' => $attribute['label']]) }} @endif
                                    @if ($this->confirmAttributeAction->isVisible()) {{ ($this->confirmAttributeAction)(['key' => $attribute['key']]) }} @endif
                                    @if ($this->rejectAttributeAction->isVisible()) {{ ($this->rejectAttributeAction)(['key' => $attribute['key']]) }} @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach ($this->dnaByCategory() as $category => $attributes)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ \App\Enums\RoleDnaCategory::from($category)->label() }}</p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($attributes as $attribute)
                                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" wire:key="attr-{{ $attribute['key'] }}">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $attribute['label'] }}</span>
                                    @if (filled($attribute['value']) && $attribute['value'] !== $attribute['label'])
                                        <span class="text-gray-700 dark:text-gray-300">{{ $attribute['value'] }}</span>
                                    @endif
                                    @if ($attribute['level'] !== 'informational')
                                        <x-filament::badge :color="\App\Enums\RequirementLevel::from($attribute['level'])->color()" size="sm">{{ \App\Enums\RequirementLevel::from($attribute['level'])->label() }}</x-filament::badge>
                                    @endif
                                    <x-filament::badge :color="\App\Enums\RoleDnaOrigin::from($attribute['origin'])->color()" size="sm">{{ \App\Enums\RoleDnaOrigin::from($attribute['origin'])->label() }}</x-filament::badge>
                                    @if ($this->evidenceAction->isVisible()) {{ ($this->evidenceAction)(['owner' => 'role_dna_version', 'id' => $dna->id, 'subject' => $attribute['key'], 'heading' => $attribute['label']]) }} @endif
                                    @if (filled($attribute['note'] ?? null))
                                        <span class="w-full text-xs text-gray-500 dark:text-gray-400">{{ $attribute['note'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">No Role DNA yet. Talent Signals and Rediscovery build it automatically from the requisition the first time they run.</p>
        @endif
    </x-filament::section>

    {{-- Talent Signals --}}
    <x-filament::section heading="Candidates — Talent Signal" description="How each active candidate aligns with the current Role DNA. Components and evidence, not a single score; context (location, pay, history) never changes the band.">
        <x-slot name="afterHeader">@if ($this->refreshSignalsAction->isVisible()) {{ $this->refreshSignalsAction }} @endif</x-slot>

        @if ($signals->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No Talent Signals yet.@if ($this->applicationsWithoutSignal() > 0) {{ $this->applicationsWithoutSignal() }} active candidate(s) can be evaluated — refresh to calculate.@endif</p>
        @else
            @if ($this->applicationsWithoutSignal() > 0)
                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ $this->applicationsWithoutSignal() }} active candidate(s) not evaluated yet.</p>
            @endif
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($signals as $signal)
                    <li class="py-3" wire:key="signal-{{ $signal->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$signal->band->color()">{{ $signal->band->label() }}</x-filament::badge>
                            <a href="{{ \App\Filament\Resources\CandidateApplications\CandidateApplicationResource::getUrl('view', ['record' => $signal->candidate_application_id]) }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $signal->candidate?->full_name }}</a>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $signal->candidateApplication?->current_stage?->label() }} · Role DNA v{{ $signal->roleDnaVersion?->version }} · {{ $signal->computed_at->diffForHumans() }}</span>
                            @if ($this->evidenceAction->isVisible()) {{ ($this->evidenceAction)(['owner' => 'talent_signal', 'id' => $signal->id, 'heading' => 'Talent Signal — '.$signal->candidate?->full_name]) }} @endif
                        </div>
                        <ul class="mt-1 grid grid-cols-1 gap-x-6 gap-y-0.5 text-sm text-gray-700 sm:grid-cols-2 dark:text-gray-300">
                            @foreach ($signal->components['items'] ?? [] as $component)
                                <li><span class="font-medium text-gray-900 dark:text-gray-100">{{ $component['label'] }}:</span> {{ $component['summary'] }}</li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    {{-- Talent Rediscovery --}}
    <x-filament::section heading="Talent Rediscovery" :description="$run ? 'Last run '.$run->created_at->diffForHumans().' by '.($run->runBy?->name ?? 'system').' — '.$run->candidates_scanned.' known candidate(s) compared with Role DNA v'.($run->roleDnaVersion?->version ?? '?').'.' : 'Find past candidates and talent-pool members who fit this role.'">
        <x-slot name="afterHeader">@if ($this->runRediscoveryAction->isVisible()) {{ $this->runRediscoveryAction }} @endif</x-slot>

        @if ($run && $run->results->isNotEmpty())
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($run->results as $result)
                    <li class="flex flex-col gap-2 py-3 lg:flex-row lg:items-start lg:justify-between" wire:key="result-{{ $result->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">#{{ $result->rank }}</span>
                                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $result->candidate?->full_name }}</span>
                                <x-filament::badge :color="$result->band->color()" size="sm">{{ $result->band->label() }}</x-filament::badge>
                                @if ($result->do_not_contact)
                                    <x-filament::badge color="danger" size="sm">Opted out of contact</x-filament::badge>
                                @endif
                                @if ($result->status->value !== 'suggested')
                                    <x-filament::badge :color="$result->status->color()" size="sm">{{ $result->status->label() }}</x-filament::badge>
                                @endif
                            </div>
                            <ul class="mt-1 list-disc pl-5 text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($result->summary['reasons'] ?? [] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($this->evidenceAction->isVisible()) {{ ($this->evidenceAction)(['owner' => 'rediscovery_result', 'id' => $result->id, 'heading' => 'Why '.$result->candidate?->full_name.'?']) }} @endif
                            @if ($result->status->value === 'suggested')
                                @if ($this->addToRequisitionAction->isVisible()) {{ ($this->addToRequisitionAction)(['result' => $result->id]) }} @endif
                                @if ($this->addToPoolAction->isVisible()) {{ ($this->addToPoolAction)(['result' => $result->id]) }} @endif
                                @if ($this->dismissResultAction->isVisible()) {{ ($this->dismissResultAction)(['result' => $result->id]) }} @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @elseif ($run)
            <p class="text-sm text-gray-600 dark:text-gray-300">No known candidate showed strong or partial alignment with the current Role DNA.</p>
        @endif
    </x-filament::section>

    {{-- Hiring Memory --}}
    <x-filament::section heading="Hiring Memory" :description="'Recorded outcomes for this requisition. '.$this->designationMemoryCount().' record(s) exist for this designation overall.'">
        @if ($memory->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">Nothing recorded yet — hires, rejections, declined offers and the requisition's outcome are captured automatically as they happen.</p>
        @else
            <ul class="space-y-2">
                @foreach ($memory as $record)
                    <li class="flex flex-wrap items-center gap-2 text-sm" wire:key="memory-{{ $record->id }}">
                        <x-filament::badge :color="$record->memory_type->color()" size="sm">{{ $record->memory_type->label() }}</x-filament::badge>
                        <span class="text-gray-800 dark:text-gray-200">{{ $record->summary }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $record->captured_at->toDateString() }}@if ($record->version > 1) · v{{ $record->version }} (corrected)@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
