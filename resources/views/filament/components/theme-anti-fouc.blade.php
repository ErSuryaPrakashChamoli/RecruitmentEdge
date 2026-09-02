@php
    $theme = \App\Enums\AppTheme::fromValueOrDefault(auth()->user()?->theme)->value;
@endphp
<script>
    document.documentElement.setAttribute('data-app-theme', {{ \Illuminate\Support\Js::from($theme) }});
</script>
