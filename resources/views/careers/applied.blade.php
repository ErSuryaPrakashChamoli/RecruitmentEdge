<x-portal.layout title="Application received" section="Careers" :home="route('careers.index')">
    {{-- SEC-88-09: the same message for every submission; it never confirms whether the details were already known. --}}
    <x-portal.card title="Thank you for applying!">
        <p class="text-sm">
            We received your application for <span class="font-medium">{{ $posting->title }}</span>.
        </p>
        <p class="mt-3 text-sm text-ink-muted">Our recruitment team will be in touch. If you have candidate portal access, you can follow your application there.</p>
        <a href="{{ route('careers.index') }}" class="mt-4 inline-block text-sm text-brand hover:underline">See other open positions</a>
    </x-portal.card>
</x-portal.layout>
