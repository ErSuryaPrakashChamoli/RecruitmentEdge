<x-portal.layout title="Application received" section="Careers" :home="route('careers.index')">
    <x-portal.card :title="session('careers_existing') ? 'You have already applied' : 'Thank you for applying!'">
        <p class="text-sm">
            @if (session('careers_existing'))
                We already have your application for <span class="font-medium">{{ $posting->title }}</span>.
            @else
                We received your application for <span class="font-medium">{{ $posting->title }}</span>.
            @endif
            @if (session('careers_reference'))
                Your reference is <span class="font-medium">{{ session('careers_reference') }}</span>.
            @endif
        </p>
        <p class="mt-3 text-sm text-ink-muted">Our recruitment team will be in touch. If you have candidate portal access, you can follow your application there.</p>
        <a href="{{ route('careers.index') }}" class="mt-4 inline-block text-sm text-brand hover:underline">See other open positions</a>
    </x-portal.card>
</x-portal.layout>
