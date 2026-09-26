@if ($rows === null)
    <p class="text-sm text-gray-600 dark:text-gray-300">This evidence is not available to you.</p>
@elseif ($rows->isEmpty())
    <p class="text-sm text-gray-600 dark:text-gray-300">No evidence was recorded for this value.</p>
@else
    <ul class="space-y-3">
        @foreach ($rows as $row)
            <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$row->evidence_type->color()" size="sm">{{ $row->evidence_type->label() }}</x-filament::badge>
                    @if ($row->isAiDerived())
                        <x-filament::badge :color="$row->verification_status->color()" size="sm">{{ $row->verification_status->label() }}</x-filament::badge>
                    @endif
                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $row->label }}</span>
                </div>
                @if (filled($row->value))
                    <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ $row->value }}</p>
                @endif
                @if (filled($row->explanation))
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $row->explanation }}</p>
                @endif
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    @if ($row->source_type)
                        Source: {{ class_basename($row->source_type) }} #{{ $row->source_id }} ·
                    @endif
                    @if ($row->observed_at)
                        Observed {{ $row->observed_at->toDayDateTimeString() }} ·
                    @endif
                    {{ $row->generator }} ({{ $row->generator_version }})
                    @if ($row->ai_model)
                        · model {{ $row->ai_model }}
                    @endif
                    @if ($row->verifiedBy)
                        · {{ strtolower($row->verification_status->label()) }} by {{ $row->verifiedBy->name }}
                    @endif
                </p>
            </li>
        @endforeach
    </ul>
@endif
