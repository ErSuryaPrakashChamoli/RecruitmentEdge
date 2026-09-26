@php($requisition = $posting->requisition)
<x-portal.layout :title="$posting->title" section="Careers" :home="route('careers.index')">
    <div>
        <a href="{{ route('careers.index') }}" class="text-sm text-brand hover:underline">&larr; All positions</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $posting->title }}</h1>
        <p class="text-sm text-ink-muted">{{ $requisition->department?->name }} · {{ $requisition->location?->name }} · {{ $requisition->employment_type?->label() }}</p>
    </div>

    <x-portal.card>
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            @if ($requisition->experience_min !== null || $requisition->experience_max !== null)
                <div><dt class="text-ink-muted">Experience</dt><dd class="font-medium">{{ $requisition->experience_min ?? 0 }}–{{ $requisition->experience_max ?? '+' }} years</dd></div>
            @endif
            @if ($requisition->qualification)
                <div><dt class="text-ink-muted">Qualification</dt><dd class="font-medium">{{ $requisition->qualification }}</dd></div>
            @endif
            @if ($posting->show_salary && $requisition->salary_max)
                <div><dt class="text-ink-muted">Salary (annual)</dt><dd class="font-medium">{{ number_format((float) $requisition->salary_min) }} – {{ number_format((float) $requisition->salary_max) }}</dd></div>
            @endif
            @if ($posting->closes_at)
                <div><dt class="text-ink-muted">Apply by</dt><dd class="font-medium">{{ $posting->closes_at->format('d M Y') }}</dd></div>
            @endif
        </dl>
        @if (! empty($requisition->skills))
            <p class="mt-4 text-sm"><span class="text-ink-muted">Skills:</span> {{ implode(', ', (array) $requisition->skills) }}</p>
        @endif
        <div class="mt-4 whitespace-pre-line text-sm leading-relaxed">{{ $posting->description }}</div>
    </x-portal.card>

    <x-portal.card title="Apply for this position" description="Your details are used only to process your application.">
        <form method="POST" action="{{ route('careers.apply', $posting->public_slug) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <div class="hidden" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            @foreach (['full_name' => ['Full name', 'text', true], 'email' => ['Email', 'email', true], 'mobile' => ['Mobile', 'tel', true], 'current_city' => ['Current city', 'text', false], 'current_company' => ['Current company', 'text', false], 'total_experience' => ['Total experience (years)', 'number', false]] as $field => [$label, $type, $required])
                <label class="flex flex-col gap-1 text-sm font-medium">
                    {{ $label }}
                    <input type="{{ $type }}" name="{{ $field }}" value="{{ old($field) }}" @required($required) @if ($type === 'number') min="0" max="60" step="0.5" @endif class="@include('portal.partials.input-class')">
                </label>
            @endforeach
            <label class="flex flex-col gap-1 text-sm font-medium sm:col-span-2">
                Resume (PDF or Word, up to 5 MB)
                <input type="file" name="resume" required accept=".pdf,.doc,.docx" class="@include('portal.partials.input-class')">
            </label>
            <fieldset class="flex flex-col gap-2 text-sm sm:col-span-2">
                <label class="flex items-start gap-2"><input type="checkbox" name="privacy_consent" value="1" required class="mt-1 rounded border-line"> I agree to {{ config('app.name') }} processing my details for recruitment.</label>
                <label class="flex items-start gap-2"><input type="checkbox" name="consent_email" value="1" @checked(old('consent_email', true)) class="mt-1 rounded border-line"> Email me about my application.</label>
                <label class="flex items-start gap-2"><input type="checkbox" name="consent_whatsapp" value="1" @checked(old('consent_whatsapp')) class="mt-1 rounded border-line"> I'm happy to receive WhatsApp updates.</label>
            </fieldset>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Submit application</button>
            </div>
        </form>
    </x-portal.card>
</x-portal.layout>
