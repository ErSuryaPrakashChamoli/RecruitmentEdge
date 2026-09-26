<x-portal.layout title="Careers" section="Careers" :home="route('careers.index')">
    <div>
        <h1 class="text-2xl font-semibold">Open positions</h1>
        <p class="text-sm text-ink-muted">Find a role at {{ config('app.name') }} and apply in a few minutes.</p>
    </div>

    <form method="GET" action="{{ route('careers.index') }}" class="flex gap-2" role="search">
        <label class="sr-only" for="q">Search positions</label>
        <input id="q" type="search" name="q" value="{{ $search }}" placeholder="Search by title" class="@include('portal.partials.input-class')">
        <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Search</button>
    </form>

    <x-portal.card>
        @forelse ($postings as $posting)
            <a href="{{ route('careers.show', $posting->public_slug) }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3 last:border-b-0 hover:bg-surface-muted">
                <div>
                    <p class="font-medium">{{ $posting->title }}</p>
                    <p class="text-xs text-ink-muted">{{ $posting->requisition->department?->name }} · {{ $posting->requisition->location?->name }}</p>
                </div>
                <span class="text-sm text-brand">View &rarr;</span>
            </a>
        @empty
            <p class="text-sm text-ink-muted">There are no open positions right now. Please check back soon.</p>
        @endforelse
    </x-portal.card>

    {{ $postings->links() }}
</x-portal.layout>
