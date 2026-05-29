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
    <style>
        /* Single calm entrance (this layout has no JS); honors reduced-motion. */
        @keyframes ageReveal { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        .reveal   { animation: ageReveal 0.55s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .reveal-2 { animation-delay: 0.07s; }
        .reveal-3 { animation-delay: 0.14s; }
        @media (prefers-reduced-motion: reduce) {
            .reveal, .reveal-2, .reveal-3 { animation: none; }
        }
    </style>
</head>
<body class="min-h-screen bg-paper text-ink-900">
<div class="min-h-screen grid lg:grid-cols-2">

    {{-- ── Editorial left panel ── --}}
    <aside class="hidden lg:flex flex-col justify-between bg-forest-900 text-forest-100 p-12 relative overflow-hidden">
        {{-- Layered depth: dot grid + soft glow + base vignette --}}
        <div class="absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(circle at 20% 25%, white 1px, transparent 1px); background-size: 26px 26px;"></div>
        <div class="absolute -top-32 -right-24 w-[26rem] h-[26rem] rounded-full bg-forest-700/40 blur-3xl"></div>
        <div class="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/25 to-transparent"></div>

        {{-- Brand --}}
        <div class="relative reveal">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-forest-700 grid place-items-center font-serif text-lg font-semibold shadow-lg shadow-black/20">A</div>
                <div>
                    <div class="font-serif text-xl font-semibold tracking-snug text-paper">AgeSense</div>
                    <div class="text-[11px] uppercase tracking-[0.12em] text-forest-300 font-medium mt-0.5">OSCA · Pagsanjan, Laguna</div>
                </div>
            </div>
        </div>

        {{-- Headline + WHO framework --}}
        <div class="relative max-w-md reveal reveal-2">
            <div class="text-[10.5px] uppercase tracking-[0.14em] text-forest-300 font-semibold mb-4">UN Decade of Healthy Ageing · 2021–2030</div>
            <h2 class="font-serif text-[40px] leading-[1.05] font-semibold tracking-snug text-paper text-balance">
                Profiling and analytics for the seniors of our barangays.
            </h2>
            <p class="mt-5 text-forest-200 text-[14.5px] leading-relaxed max-w-sm">
                Built on the WHO Healthy Ageing framework, AgeSense finds and prioritises the seniors who need care most.
            </p>

            <div class="mt-7 border-t border-forest-700/70 pt-6 space-y-3">
                <div class="text-[10.5px] uppercase tracking-[0.13em] text-forest-300 font-semibold">Three assessed domains</div>
                @foreach ([
                    ['Intrinsic Capacity', 'physical and mental reserve'],
                    ['Environment', 'home, finances, and community'],
                    ['Functional Ability', 'independence in daily living'],
                ] as [$domain, $desc])
                <div class="flex items-baseline gap-3">
                    <span class="w-1.5 h-1.5 rounded-full bg-forest-400 flex-shrink-0 translate-y-[-2px]"></span>
                    <span class="text-paper text-[13.5px] font-medium">{{ $domain }}</span>
                    <span class="text-forest-300 text-[12px] ml-auto whitespace-nowrap">{{ $desc }}</span>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Stat band --}}
        <div class="relative reveal reveal-3 grid grid-cols-3 max-w-md border-t border-forest-700 pt-6">
            <div class="pr-4">
                <div class="font-serif text-[28px] font-semibold text-paper tnum leading-none">3</div>
                <div class="text-[10.5px] uppercase tracking-wider text-forest-300 mt-1.5">WHO Domains</div>
            </div>
            <div class="px-4 border-l border-forest-700">
                <div class="font-serif text-[28px] font-semibold text-paper tnum leading-none">K=4</div>
                <div class="text-[10.5px] uppercase tracking-wider text-forest-300 mt-1.5">Health Groups</div>
            </div>
            <div class="pl-4 border-l border-forest-700">
                <div class="font-serif text-[28px] font-semibold text-paper tnum leading-none">16</div>
                <div class="text-[10.5px] uppercase tracking-wider text-forest-300 mt-1.5">Barangays</div>
            </div>
        </div>
    </aside>

    {{-- ── Form ── --}}
    <main class="flex items-center justify-center px-6 py-12">
        <div class="w-full max-w-sm reveal reveal-2">
            <div class="lg:hidden mb-8 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-forest-800 text-forest-100 grid place-items-center font-serif text-lg font-semibold">A</div>
                <div class="font-serif text-xl font-semibold">AgeSense</div>
            </div>

            <div class="eyebrow text-forest-700">Sign in</div>
            <h1 class="font-serif text-[34px] font-semibold tracking-snug mt-2 text-balance">Welcome back.</h1>
            <p class="text-ink-500 text-[13.5px] mt-2">Access the OSCA analytics workspace for Pagsanjan.</p>

            @if ($errors->any())
                <div class="mt-6 flex items-start gap-2 rounded-xl border border-critical-200 bg-critical-50 px-3.5 py-2.5 text-[13px] text-critical-700" role="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            {{-- No Alpine on this layout (only app.css is loaded), so the button state is
                 handled with plain JS below — never x-data / template, which render blank. --}}
            <form method="POST" action="{{ route('login') }}" id="login-form" class="mt-8 space-y-5">
                @csrf
                <div>
                    <label for="email" class="eyebrow block mb-1.5">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                        autocomplete="username" inputmode="email"
                        class="form-input py-3 text-[14px]" placeholder="you@osca.local" />
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="eyebrow">Password</label>
                        <span class="text-[11px] text-ink-400 cursor-help" title="Ask your OSCA administrator to reset it.">Forgot?</span>
                    </div>
                    <div class="relative">
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               class="form-input py-3 text-[14px] pr-11" />
                        <button type="button" id="toggle-pw"
                                onclick="togglePassword()"
                                aria-label="Show password"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-ink-300 hover:text-ink-600 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-500/40 transition-colors">
                            {{-- Eye open (password hidden) --}}
                            <svg id="pw-icon-show" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            {{-- Eye slash (password visible) --}}
                            <svg id="pw-icon-hide" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 012.18-3.516M6.53 6.53A9.97 9.97 0 0112 5c4.477 0 8.268 2.943 9.542 7a10.02 10.02 0 01-4.293 5.208M15 12a3 3 0 11-4.243-4.243M3 3l18 18"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <label class="inline-flex items-center gap-2 text-[13px] text-ink-700 select-none">
                    <input type="checkbox" name="remember" class="rounded border-paper-rule text-forest-700 focus:ring-forest-500" />
                    Keep me signed in on this device
                </label>

                <button type="submit" id="login-btn"
                        class="btn btn-primary w-full justify-center gap-2 py-3 text-[14px] shadow-sm">
                    <span id="login-spinner" class="btn-spinner hidden" aria-hidden="true"></span>
                    <span id="login-btn-label">Sign in to AgeSense</span>
                    <svg id="login-arrow" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </button>
            </form>
            <script>
                function togglePassword() {
                    var input  = document.getElementById('password');
                    var show   = document.getElementById('pw-icon-show');
                    var hide   = document.getElementById('pw-icon-hide');
                    var btn    = document.getElementById('toggle-pw');
                    var hidden = input.type === 'password';
                    input.type = hidden ? 'text' : 'password';
                    show.classList.toggle('hidden', hidden);
                    hide.classList.toggle('hidden', !hidden);
                    btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
                }
                (function () {
                    var form = document.getElementById('login-form');
                    if (!form) return;
                    form.addEventListener('submit', function () {
                        var btn     = document.getElementById('login-btn');
                        var label   = document.getElementById('login-btn-label');
                        var spinner = document.getElementById('login-spinner');
                        var arrow   = document.getElementById('login-arrow');
                        if (btn)     btn.disabled = true;
                        if (spinner) spinner.classList.remove('hidden');
                        if (arrow)   arrow.classList.add('hidden');
                        if (label)   label.textContent = 'Signing in…';
                    });
                })();
            </script>

            <p class="text-[11px] text-ink-400 mt-10 leading-relaxed">
                AgeSense · WHO Healthy Ageing framework · OSCA Pagsanjan, Laguna
            </p>
        </div>
    </main>
</div>
</body>
</html>
