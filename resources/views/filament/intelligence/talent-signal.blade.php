<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::badge :color="$snapshot->band->color()">{{ $snapshot->band->label() }}</x-filament::badge>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            Role DNA v{{ $snapshot->roleDnaVersion?->version }} · rules {{ $snapshot->rules_version }} · data completeness {{ $snapshot->completeness_pct }}% · computed {{ $snapshot->computed_at->diffForHumans() }}
        </span>
    </div>

    <p class="text-xs text-gray-600 dark:text-gray-400">
        Band rules: strong = at least 80% of required skills and experience not below the range; low = under 40% of required skills or experience more than 2 years below; insufficient evidence = under 40% of the needed facts recorded. Location, pay, notice, history and interviews are context and never change the band. Skill matching is literal tag matching.
    </p>

    <ul class="space-y-3">
        @foreach ($snapshot->components['items'] ?? [] as $key => $item)
            <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                    <x-filament::badge size="sm" :color="['match' => 'success', 'partial' => 'warning', 'gap' => 'danger', 'unknown' => 'gray', 'context' => 'info'][$item['status']] ?? 'gray'">{{ ucfirst($item['status']) }}</x-filament::badge>
                </div>
                <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $item['summary'] }}</p>
                @if (! empty($item['missing']))
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Not on profile: {{ implode(', ', $item['missing']) }}</p>
                @endif
                @foreach ($evidence->get($key, collect()) as $row)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">• {{ $row->evidence_type->label() }}: {{ $row->label }}@if ($row->value) — {{ $row->value }}@endif @if ($row->observed_at)({{ $row->observed_at->toDateString() }})@endif</p>
                @endforeach
            </li>
        @endforeach
    </ul>
</div>
