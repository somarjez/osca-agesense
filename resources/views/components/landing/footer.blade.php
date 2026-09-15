<footer class="bg-navy-900 text-navy-300">
    <div class="shell py-16 grid sm:grid-cols-2 lg:grid-cols-4 gap-10">
        <div class="sm:col-span-2 lg:col-span-1">
            <div class="flex items-center gap-2.5">
                <x-app-logo :size="32" />
                <span class="font-serif text-[17px] font-semibold text-paper">AgeSense</span>
            </div>
            <p class="mt-3 text-[12.5px] leading-relaxed text-navy-400 max-w-xs">
                Explainable machine learning and decision support for healthy-ageing profiling.
            </p>
        </div>

        <div>
            <div class="text-[10.5px] uppercase tracking-[0.13em] font-semibold text-navy-400">Platform</div>
            <ul class="mt-3 space-y-2 text-[13px]">
                <li><a href="#about" class="hover:text-paper transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/50 rounded">About</a></li>
                <li><a href="#features" class="hover:text-paper transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/50 rounded">Features</a></li>
                <li><a href="#how-it-works" class="hover:text-paper transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/50 rounded">How It Works</a></li>
                <li><a href="#who-framework" class="hover:text-paper transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/50 rounded">WHO Framework</a></li>
            </ul>
        </div>

        <div>
            <div class="text-[10.5px] uppercase tracking-[0.13em] font-semibold text-navy-400">Resources</div>
            <ul class="mt-3 space-y-2 text-[13px]">
                <li><a href="#responsible-use" class="hover:text-paper transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/50 rounded">Responsible Use</a></li>
                <li><a href="{{ route('help') }}" class="hover:text-paper transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/50 rounded">Help Center</a></li>
                <li><a href="{{ route('login') }}" target="_blank" rel="noopener noreferrer" class="hover:text-paper transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/50 rounded">Log In</a></li>
            </ul>
        </div>

        <div>
            <div class="text-[10.5px] uppercase tracking-[0.13em] font-semibold text-navy-400">Institution</div>
            <ul class="mt-3 space-y-1.5 text-[13px] text-navy-300">
                <li>Laguna State Polytechnic University</li>
                <li>College of Computer Studies</li>
                <li>Santa Cruz Main Campus</li>
            </ul>
        </div>
    </div>

    <div class="border-t border-navy-700">
        <div class="shell py-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <p class="text-[11.5px] text-navy-400 max-w-xl leading-relaxed">
                Decision support only. AgeSense does not replace professional, clinical, or official government judgment.
            </p>
            <p class="text-[11.5px] text-navy-500 whitespace-nowrap">&copy; 2026 AgeSense. All rights reserved.</p>
        </div>
    </div>
</footer>
