<x-filament-panels::page>
    <form wire:submit="ask" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit">
            Ask
        </x-filament::button>
    </form>

    @if ($answer !== null)
        <x-filament::section heading="Answer" class="mt-6">
            <p class="whitespace-pre-line">{{ $answer }}</p>

            @if ($articles->isNotEmpty())
                <div class="mt-4 space-y-1">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Matched knowledge base articles:</p>
                    <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-300">
                        @foreach ($articles as $article)
                            <li>{{ $article->title }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
