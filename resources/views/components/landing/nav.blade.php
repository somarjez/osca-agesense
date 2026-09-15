{{-- Public landing nav — transparent over the hero, gains a solid/blurred
     surface once scrolled (toggled by landing.blade.php's script). Plain JS
     only: this page loads no Alpine/Livewire bundle, same as auth/login. --}}
@php
    $links = [
        ['href' => '#about', 'label' => 'About'],
        ['href' => '#who-framework', 'label' => 'Framework'],
        ['href' => '#how-it-works', 'label' => 'Platform'],
        ['href' => '#features', 'label' => 'Features'],
        ['href' => '#stakeholders', 'label' => 'Stakeholders'],
        ['href' => '#responsible-use', 'label' => 'Responsible Use'],
    ];
@endphp
<header id="site-nav" class="sticky top-0 z-40 bg-transparent border-b border-transparent">
    <nav class="shell h-[76px] flex items-center justify-between gap-4" aria-label="Primary">
        <a href="#home" class="flex items-center gap-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/40 rounded-lg">
            <x-app-logo :size="34" />
            <span class="font-serif text-[18px] font-semibold tracking-tightish text-ink-900">AgeSense</span>
        </a>

        <ul class="hidden lg:flex items-center gap-8">
            @foreach ($links as $link)
                <li>
                    <a href="{{ $link['href'] }}"
                       class="text-[13.5px] font-medium text-ink-700 hover:text-accent-700 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/40 rounded">
                        {{ $link['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="hidden lg:block">
            <a href="{{ route('login') }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary text-[13.5px] px-5 py-2.5 shadow-sm">
                Log In
            </a>
        </div>

        <button type="button" id="nav-menu-toggle" aria-expanded="false" aria-controls="nav-mobile-panel"
                class="lg:hidden inline-flex items-center justify-center w-10 h-10 rounded-xl text-ink-800 hover:bg-white/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/40">
            <span class="sr-only">Open menu</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
            </svg>
        </button>
    </nav>

    <div id="nav-mobile-panel" class="hidden lg:hidden border-t border-paper-rule bg-paper">
        <ul class="shell py-3 space-y-1">
            @foreach ($links as $link)
                <li>
                    <a href="{{ $link['href'] }}"
                       class="block px-2 py-2.5 rounded-lg text-[14px] font-medium text-ink-700 hover:bg-paper-2 hover:text-accent-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/40">
                        {{ $link['label'] }}
                    </a>
                </li>
            @endforeach
            <li class="pt-2">
                <a href="{{ route('login') }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary w-full justify-center text-[14px] py-2.5">
                    Log In
                </a>
            </li>
        </ul>
    </div>
</header>
