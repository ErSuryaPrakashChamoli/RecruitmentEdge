@props(['title' => null, 'section' => 'Candidate Portal', 'home' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ \App\Services\Branding::tenantName() }} {{ $section }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-3">
            <a href="{{ $home ?? (auth('candidate')->check() ? route('portal.dashboard') : route('portal.login')) }}" class="flex items-center gap-2 font-semibold">
                <span class="inline-flex size-8 items-center justify-center rounded-lg bg-brand text-sm text-brand-ink">RE</span>
                <span>{{ \App\Services\Branding::tenantName() }} <span class="font-normal text-ink-muted">· {{ $section }}</span></span>
            </a>

            @auth('candidate')
                <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="Portal">
                    @foreach (['portal.dashboard' => 'Applications', 'portal.documents.index' => 'Documents', 'portal.profile.edit' => 'Profile'] as $route => $label)
                        <a href="{{ route($route) }}" @class(['rounded-md px-3 py-1.5', 'bg-brand-soft font-medium text-brand' => request()->routeIs($route), 'text-ink-muted hover:bg-surface-muted' => ! request()->routeIs($route)])>{{ $label }}</a>
                    @endforeach
                    <form method="POST" action="{{ route('portal.logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md px-3 py-1.5 text-ink-muted hover:bg-surface-muted">Sign out</button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main class="mx-auto flex max-w-4xl flex-col gap-6 px-4 py-8">
        @if (session('status'))
            <div role="status" class="rounded-lg border border-line bg-success-soft px-4 py-3 text-sm text-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-line bg-danger-soft px-4 py-3 text-sm text-danger">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
