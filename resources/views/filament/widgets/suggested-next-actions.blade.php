<x-filament-widgets::widget>
    <x-filament::section heading="Suggested next steps" description="Rule-based suggestions from each candidate's current state — no AI. Turn one into an action to own it." collapsible>
        @php $suggestions = $this->getSuggestions(); @endphp

        @if ($suggestions->isEmpty())
            <x-recruitment.empty-state
                icon="heroicon-o-check-circle"
                heading="Nothing to suggest"
                description="Every visible candidate is on track."
            />
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($suggestions as $index => $suggestion)
                    <li class="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-center sm:gap-3" wire:key="suggestion-{{ $index }}">
                        <x-filament::badge :color="$suggestion->priority->color()" class="shrink-0 self-start sm:self-center">
                            {{ $suggestion->priority->label() }}
                        </x-filament::badge>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $suggestion->suggestedAction }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($link = $this->linkFor($suggestion))
                                    <a href="{{ $link }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $suggestion->entity->candidate?->full_name ?? $suggestion->entity->candidateApplication?->candidate?->full_name ?? $suggestion->entity->code ?? class_basename($suggestion->entity) }}</a> ·
                                @endif
                                {{ $suggestion->reason }}
                                @if ($suggestion->owner) · Owner: {{ $suggestion->owner->fullName() }} @endif
                            </p>
                            @foreach ($suggestion->evidence as $line)
                                <p class="text-xs text-gray-500 dark:text-gray-400">• {{ $line }}</p>
                            @endforeach
                        </div>

                        <x-filament::button size="xs" color="gray" icon="heroicon-o-plus" wire:click="createAction({{ $index }})" class="shrink-0 self-start sm:self-center">
                            Create action
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
