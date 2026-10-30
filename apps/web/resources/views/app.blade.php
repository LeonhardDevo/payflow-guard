@php
    $locale = str_replace('_', '-', app()->getLocale());
    $appearanceValue = in_array($appearance ?? 'system', ['light', 'dark', 'system'], true) ? ($appearance ?? 'system') : 'system';
    $component = (string) ($page['component'] ?? '');
    $isMarketing = str_starts_with($component, 'marketing/');
    $metaTitle = __('marketing.meta.title', ['product' => config('app.name')]);
    $metaDescription = __('marketing.meta.description', ['product' => config('app.name')]);
    $canonical = url()->current();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" @class(['dark' => $appearanceValue === 'dark']) data-appearance="{{ $appearanceValue }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light dark">
        <meta name="theme-color" content="#F7F8FA" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#070B14" media="(prefers-color-scheme: dark)">

        {{-- Resolve the theme before first paint (no flash): localStorage wins, then the cookie, then the OS. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                var fromCookie = {{ Js::from($appearanceValue) }};
                var stored = null;

                try {
                    stored = window.localStorage.getItem('appearance');
                } catch (e) {
                    stored = null;
                }

                var appearance = stored === 'light' || stored === 'dark' || stored === 'system' ? stored : fromCookie;
                var dark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                var root = document.documentElement;

                root.classList.toggle('dark', dark);
                root.style.colorScheme = dark ? 'dark' : 'light';
                root.setAttribute('data-appearance', appearance);
            })();
        </script>

        {{-- Page background before CSS arrives (matches --background in resources/css/brand.css). --}}
        <style nonce="{{ Vite::cspNonce() }}">
            html { background-color: #F7F8FA; }
            html.dark { background-color: #070B14; }
        </style>

        <meta name="description" content="{{ $metaDescription }}" data-inertia="description">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ $metaTitle }}" data-inertia="og:title">
        <meta property="og:description" content="{{ $metaDescription }}" data-inertia="og:description">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:image" content="{{ asset('og-image.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:locale" content="{{ $locale === 'ru' ? 'ru_RU' : 'en_US' }}">
        <meta property="og:locale:alternate" content="{{ $locale === 'ru' ? 'en_US' : 'ru_RU' }}">
        <meta name="twitter:card" content="summary_large_image">

        @if ($isMarketing)
            <link rel="canonical" href="{{ $canonical }}">
            <link rel="alternate" hreflang="en" href="{{ $canonical }}?lang=en">
            <link rel="alternate" hreflang="ru" href="{{ $canonical }}?lang=ru">
            <link rel="alternate" hreflang="x-default" href="{{ $canonical }}">
        @else
            <meta name="robots" content="noindex">
        @endif

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $isMarketing ? $metaTitle : config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
