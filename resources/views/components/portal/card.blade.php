@props(['title' => null, 'description' => null])

<section {{ $attributes->class('rounded-xl border border-line bg-surface p-5 shadow-sm') }}>
    @if ($title)
        <header class="mb-4">
            <h2 class="text-base font-semibold">{{ $title }}</h2>
            @if ($description)
                <p class="mt-0.5 text-sm text-ink-muted">{{ $description }}</p>
            @endif
        </header>
    @endif
    {{ $slot }}
</section>
