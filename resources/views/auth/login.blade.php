<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in — AgeSense · OSCA Pagsanjan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600;8..60,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-paper text-ink-900">
<div class="min-h-screen grid lg:grid-cols-2">

    {{-- ── Editorial left panel ── --}}
    <aside class="hidden lg:flex flex-col justify-between bg-forest-900 text-forest-100 p-12 relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(circle at 20% 30%, white 1px, transparent 1px); background-size: 24px 24px;"></div>

        <div class="relative">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-md bg-forest-700 grid place-items-center font-serif text-lg font-semibold">A</div>
                <div>
                    <div class="font-serif text-xl font-semibold tracking-snug text-paper">AgeSense</div>
                    <div class="text-[11px] uppercase tracking-[0.12em] text-forest-300 font-medium mt-0.5">OSCA · Pagsanjan, Laguna</div>
                </div>
            </div>
        </div>

        <div class="relative max-w-md">
            <div class="text-[11px] uppercase tracking-[0.14em] text-forest-300 font-semibold mb-4">UN Decade of Healthy Ageing · 2021–2030</div>
            <h2 class="font-serif text-[40px] leading-[1.05] font-semibold tracking-snug text-paper">
                Profiling and analytics for the seniors of our barangays.
            </h2>
            <p class="mt-5 text-forest-200 text-[14.5px] leading-relaxed max-w-sm">
                AgeSense applies the WHO Healthy Ageing framework — Intrinsic Capacity, Environment, and Functional Ability — to identify and prioritize the seniors who need our care most.
            </p>
        </div>

        <div class="relative grid grid-cols-3 gap-6 max-w-md text-sm border-t border-forest-700 pt-6">
            <div>
                <div class="font-serif text-2xl font-semibold text-paper tnum">3</div>
                <div class="text-[11px] uppercase tracking-wider text-forest-300 mt-1">WHO Domains</div>
            </div>
            <div>
                <div class="font-serif text-2xl font-semibold text-paper tnum">K=3</div>
                <div class="text-[11px] uppercase tracking-wider text-forest-300 mt-1">Senior Profiles</div>
            </div>
            <div>
                <div class="font-serif text-2xl font-semibold text-paper tnum">17</div>
                <div class="text-[11px] uppercase tracking-wider text-forest-300 mt-1">Barangays</div>
            </div>
        </div>
    </aside>

    {{-- ── Form ── --}}
    <main class="flex items-center justify-center px-6 py-12">
        <div class="w-full max-w-sm">
            <div class="lg:hidden mb-8 flex items-center gap-3">
                <div class="w-9 h-9 rounded-md bg-forest-800 text-forest-100 grid place-items-center font-serif text-lg font-semibold">A</div>
                <div class="font-serif text-xl font-semibold">AgeSense</div>
            </div>

            <div class="eyebrow">Sign in</div>
            <h1 class="font-serif text-[32px] font-semibold tracking-snug mt-2">Welcome back.</h1>
            <p class="text-ink-500 text-[13.5px] mt-2">Access the OSCA analytics workspace for Pagsanjan.</p>

            @if ($errors->any())
                <div class="mt-6 badge badge-critical w-full justify-start">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-4">
                @csrf
                <div>
                    <label for="email" class="eyebrow block mb-1.5">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                        class="form-input" placeholder="you@osca.local" />
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="eyebrow">Password</label>
                        <a href="#" class="text-[11px] text-forest-700 hover:text-forest-900 font-semibold">Forgot?</a>
                    </div>
                    <input id="password" name="password" type="password" required class="form-input" />
                </div>

                <label class="inline-flex items-center gap-2 text-[13px] text-ink-700">
                    <input type="checkbox" name="remember" class="rounded border-paper-rule text-forest-700 focus:ring-forest-500" />
                    Keep me signed in on this device
                </label>

                <button type="submit" class="btn btn-primary w-full justify-center py-2.5 text-[14px]">
                    Sign in to AgeSense
                </button>
            </form>

            <div class="mt-8 pt-6 border-t border-paper-rule">
                <div class="eyebrow mb-2">Default local account</div>
                <div class="font-mono text-[12px] text-ink-700 space-y-0.5">
                    <div>admin@osca.local</div>
                    <div>password</div>
                </div>
            </div>

            <p class="text-[11px] text-ink-400 mt-10">
                AgeSense · WHO Healthy Ageing framework · OSCA Pagsanjan, Laguna
            </p>
        </div>
    </main>
</div>
</body>
</html>
