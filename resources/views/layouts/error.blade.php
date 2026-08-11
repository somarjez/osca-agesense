<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'AgeSense')</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-paper dark:bg-[#131917] text-ink-900 dark:text-[#e4e1d8] flex flex-col">
{{-- Standalone shell for error pages — deliberately does NOT extend layouts/app.blade.php.
     That layout assumes an authenticated app shell (sidebar nav, ML service health
     check, profile dropdown); error pages must render safely for guests too, so
     this mirrors the same standalone-HTML approach already used by auth/login.blade.php. --}}
<x-gov-band />
<main class="flex-1 flex items-center justify-center px-6 py-14">
    <div class="w-full max-w-md text-center">
        <div class="flex justify-center mb-6">
            <x-app-logo :size="44" class="shadow-sm" />
        </div>
        <div class="eyebrow text-forest-700 mb-2">@yield('code')</div>
        <h1 class="font-serif text-[28px] font-semibold tracking-snug text-balance leading-[1.15] mb-4">@yield('heading')</h1>
        <div class="text-left">
            <x-alert type="error">
                @yield('message')
            </x-alert>
        </div>
        @hasSection('cta')
            @yield('cta')
        @else
            <a href="{{ route('dashboard') }}"
               class="btn btn-primary inline-flex mt-8 px-5 py-2.5 text-[13.5px]">
                Back to Dashboard
            </a>
        @endif
    </div>
</main>
</body>
</html>
