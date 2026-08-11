@auth
@php
    $idleConfig = [
        'idleMinutes' => (int) config('auth.idle_logout_minutes', 15),
        'warningSeconds' => (int) config('auth.idle_warning_seconds', 60),
    ];
@endphp
{{--
    No x-init here — idleLogout() is registered via Alpine.data() with its own
    init() method (resources/js/idle-logout.js), and Alpine already
    auto-invokes that on mount. See ml-service-guard.blade.php's comment for
    why an explicit x-init="init()" here would double-fire it and leak
    listeners on every wire:navigate.

    @persist means this mounts exactly once per tab session — its
    document-level activity listeners and setInterval ticker are never torn
    down and rebuilt on navigation, unlike the un-persisted <x-ml-service-guard />.
--}}
@persist('idle-warning')
<div x-data="idleLogout(@js($idleConfig))"
     data-idle-minutes="{{ $idleConfig['idleMinutes'] }}"
     data-warning-seconds="{{ $idleConfig['warningSeconds'] }}">

    <x-modal show="warning" ariaLabel="You are about to be signed out due to inactivity" max-width="max-w-sm" :closeable="true">
        <div class="flex flex-col items-center text-center">
            <div class="w-12 h-12 rounded-2xl grid place-items-center bg-info-100 text-info-700">
                <x-heroicon-o-clock class="w-6 h-6" aria-hidden="true" />
            </div>
            <h2 class="card-title mt-3.5 mb-1.5">Still there?</h2>
            <p class="text-[13px] text-ink-500 leading-relaxed">
                For your security, you'll be signed out in
                <span class="font-semibold tnum" x-text="remaining"></span> seconds due to inactivity.
            </p>
            <p class="sr-only" role="status" aria-live="polite" x-text="announceText"></p>
        </div>

        <x-slot:footer>
            <form x-ref="logoutForm" method="POST" action="{{ route('logout') }}" class="hidden" data-no-loading>
                @csrf
                <input type="hidden" name="reason" value="inactivity">
            </form>
            <button type="button" @click="$refs.logoutForm.submit()" class="btn btn-secondary flex-1 justify-center">Sign out now</button>
            <button type="button" @click="stay()" class="btn btn-primary flex-1 justify-center">Stay signed in</button>
        </x-slot:footer>
    </x-modal>
</div>
@endpersist
@endauth
