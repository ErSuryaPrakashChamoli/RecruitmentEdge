@props(['heading', 'description' => null])

<x-filament::section :heading="$heading" :description="$description">
    {{ $slot }}

    @isset($afterHeader)
        <x-slot name="afterHeader">
            {{ $afterHeader }}
        </x-slot>
    @endisset
</x-filament::section>
