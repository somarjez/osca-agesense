@auth
@unless(request()->routeIs('ml.status'))
@php
    $ml = app(\App\Services\MlService::class);
    $mlGuardConfig = [
        'navHealthUrl'  => route('ml.nav-health'),
        'wakeUrl'       => route('ml.wake'),
        'wakeStatusUrl' => route('ml.wake-status'),
        'preprocessUrl' => $ml->preprocessUrl(),
        'inferenceUrl'  => $ml->inferenceUrl(),
        'csrfToken'     => csrf_token(),
    ];
    $canControlLocal = $ml->localServiceControlAvailable();
    $canStartLocal   = $canControlLocal && (auth()->user()?->hasAnyRole(['admin', 'encoder']) ?? false);
@endphp
{{--
    No x-init here — mlServiceGuard() is registered via Alpine.data() with its
    own init() method (ml-service-guard.js), and Alpine already auto-invokes
    that on mount. An explicit x-init="init()" here double-fired it: two
    checkHealth() calls per mount, and since pollTimer/_onVisible/_onNavigating
    are single properties, the second call's IDs silently overwrote the
    first's — leaking one orphaned 45s poller and two orphaned event
    listeners on every single wire:navigate transition, compounding for the
    rest of the session.

    @persist is placed HERE (inside the @auth/@unless guard above), not
    around the <x-ml-service-guard /> call site in the layout — this whole
    block is entirely absent on /ml/status. If the persist wrapper were
    unconditional in the layout instead, leaving /ml/status would restore a
    stale *empty* persisted node (since this file rendered nothing there)
    over any later page's real one, permanently breaking the guard for the
    rest of the session. Keeping the wrapper conditional here means the
    persisted key simply doesn't exist while on /ml/status, which Livewire's
    navigate plugin already handles cleanly (destroys the old instance,
    starts fresh next time this renders) — see putPersistantElementsBack()
    in Livewire's navigate/persist.js.
--}}
@persist('ml-service-guard')
<div x-data="mlServiceGuard(@js($mlGuardConfig))">

    {{--
        Warning / wake-progress modal. Reflects CURRENT service status, not
        whether it has already been shown before this tab session — it opens
        every time the shared health poller (ml-health.js) detects a NEW
        outage (see mlServiceGuard._applyHealth()'s isDown/wasKnownDown
        check), and again whenever a pending ML-dependent operation calls
        window.OSCA.mlGate.require() while services are still down, even if
        an earlier background warning for the SAME outage was dismissed.
        `phase` (ml-service-guard.js) drives every branch below: checking |
        inactive | waking | active | error.
    --}}
    <x-modal show="modalOpen" max-width="max-w-md" ariaLabel="Analysis services status" :closeable="true">
        <div class="flex flex-col items-center text-center">
            <div class="w-12 h-12 rounded-2xl grid place-items-center"
                 :class="phase === 'active'
                    ? 'bg-low-50 dark:bg-low-900/30 text-low-700 dark:text-low-300'
                    : (phase === 'error'
                        ? 'bg-critical-50 dark:bg-critical-900/30 text-critical-700 dark:text-critical-300'
                        : 'bg-moderate-50 dark:bg-moderate-900/30 text-moderate-700 dark:text-moderate-300')">
                <x-heroicon-o-check-circle class="w-6 h-6" aria-hidden="true" x-show="phase === 'active'" x-cloak />
                <x-heroicon-o-exclamation-triangle class="w-6 h-6" aria-hidden="true" x-show="phase !== 'active'" x-cloak />
            </div>

            <h2 class="card-title mt-3.5 mb-1.5">
                <template x-if="phase === 'waking'"><span>Starting Analysis Services…</span></template>
                <template x-if="phase === 'active'"><span>Analysis Services Ready</span></template>
                <template x-if="phase === 'error'"><span>Unable to Start Analysis Services</span></template>
                <template x-if="phase !== 'waking' && phase !== 'active' && phase !== 'error'"><span>Analysis Services Inactive</span></template>
            </h2>

            <div class="w-full text-[13px] text-ink-600 dark:text-[#9aada5] leading-relaxed space-y-2">
                <template x-if="phase === 'inactive' || phase === 'checking'">
                    <p>
                        It has been a while since the analysis services were active. They may have gone to
                        sleep due to inactivity. The system is still usable, but assessments will run on a
                        lower-quality fallback until the services are back. Wake up the services to continue
                        using analysis features?
                    </p>
                </template>

                <template x-if="phase === 'waking'">
                    <p class="flex items-center justify-center gap-2 text-ink-500 dark:text-[#8a9a92] font-medium">
                        <span class="btn-spinner" aria-hidden="true"></span>
                        <span>Please wait while the required services become available… <span x-text="fmt(wakeElapsed)"></span></span>
                    </p>
                </template>

                <template x-if="phase === 'active'">
                    <p class="text-low-700 dark:text-low-300 font-medium">Analysis services are ready.</p>
                </template>

                <template x-if="phase === 'error'">
                    <div class="space-y-1.5">
                        <p class="text-critical-700 dark:text-[#e08070] font-medium">
                            Unable to start the analysis services. Please try again.
                        </p>
                        <p class="text-ink-500 dark:text-[#8a9a92]" x-show="wakeGaveUp" x-cloak>
                            Still waking up after several minutes. You can retry, or check back shortly.
                        </p>
                        <p class="text-ink-500 dark:text-[#8a9a92]" x-show="wakeError" x-cloak x-text="wakeError"></p>
                    </div>
                </template>

                @if($canControlLocal && ! $canStartLocal)
                    <p class="text-ink-500 dark:text-[#8a9a92]" x-show="phase === 'inactive' || phase === 'checking' || phase === 'error'" x-cloak>Ask an admin or encoder to start the services locally.</p>
                @endif
            </div>
        </div>

        <x-slot:footer>
            <button type="button" @click="modalOpen = false" class="btn btn-secondary flex-1 justify-center">Dismiss</button>

            @if($canStartLocal)
                <button type="button" @click="startLocal()" :disabled="starting"
                        class="btn btn-primary flex-1 justify-center">
                    <span x-show="starting" x-cloak>Starting…</span>
                    <span x-show="!starting" x-cloak>Start Services</span>
                </button>
            @elseif(! $canControlLocal)
                <button type="button" @click="startWake()" :disabled="waking || phase === 'active'"
                        class="btn btn-primary flex-1 justify-center">
                    <span x-show="waking" x-cloak>Waking…</span>
                    <span x-show="!waking && phase === 'error'" x-cloak>Retry</span>
                    <span x-show="!waking && phase !== 'error'" x-cloak>Wake Up Services</span>
                </button>
            @endif
        </x-slot:footer>
    </x-modal>

    @if($canStartLocal)
        <form x-ref="startForm" method="POST" action="{{ route('ml.start') }}" class="hidden" data-loading="Starting…">
            @csrf
        </form>
    @endif

    {{-- Live status toast — background outage recovery, and any tick that
         doesn't warrant reopening the modal (see mlServiceGuard.js). --}}
    <div x-show="toast.show"
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         role="status"
         aria-live="polite"
         aria-atomic="true"
         class="fixed top-24 right-5 z-[2000] flex items-start gap-3 max-w-sm w-full rounded-2xl border px-4 py-3 shadow-lg"
         :class="toast.type === 'success'
            ? 'bg-accent-50 border-accent-100 text-accent-700 dark:bg-accent-700/20 dark:border-accent-100/30 dark:text-accent-100'
            : 'bg-moderate-50 border-moderate-100 text-moderate-700 dark:bg-moderate-700/20 dark:border-moderate-100/30 dark:text-moderate-100'">
        <x-heroicon-o-check-circle x-show="toast.type === 'success'" x-cloak class="w-5 h-5 flex-shrink-0 mt-0.5" aria-hidden="true" />
        <x-heroicon-o-exclamation-triangle x-show="toast.type !== 'success'" x-cloak class="w-5 h-5 flex-shrink-0 mt-0.5" aria-hidden="true" />
        <p class="text-sm leading-snug flex-1" x-text="toast.message"></p>
        <button @click="toast.show = false" class="flex-shrink-0 opacity-50 hover:opacity-100 transition-opacity ml-1" aria-label="Dismiss notification">
            <x-heroicon-o-x-mark class="w-4 h-4" aria-hidden="true" />
        </button>
    </div>
</div>
@endpersist
@endunless
@endauth
