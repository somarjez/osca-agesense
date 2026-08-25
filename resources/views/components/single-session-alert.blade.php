@auth
{{--
    No x-init here — this component's only job is to listen for the
    osca:login-attempt window event dispatched by the page-lifetime poller
    in resources/js/login-attempt-watch.js (a module singleton, independent
    of this component's own lifecycle — see ml-health.js for the same
    pattern). @persist means this mounts exactly once per tab session, same
    reasoning as idle-warning.blade.php: once dismissed, it stays dismissed
    across wire:navigate instead of being torn down and rebuilt (and losing
    that "already dismissed" state) on every page.
--}}
@persist('single-session-alert')
<div x-data="{ show: false, ip: null, at: null }"
     @osca:login-attempt.window="show = true; ip = $event.detail.ip; at = $event.detail.at">

    <x-modal show="show" ariaLabel="Someone attempted to sign in to your account" max-width="max-w-sm" :closeable="true">
        <div class="flex flex-col items-center text-center">
            <div class="w-12 h-12 rounded-2xl grid place-items-center bg-moderate-100 text-moderate-700">
                <x-heroicon-o-shield-exclamation class="w-6 h-6" aria-hidden="true" />
            </div>
            <h2 class="card-title mt-3.5 mb-1.5">Someone tried to sign in</h2>
            <p class="text-[13px] text-ink-500 leading-relaxed">
                A sign-in attempt for your account was just blocked because you're already
                signed in here<span x-show="ip" x-cloak> from IP <span class="font-semibold tnum" x-text="ip"></span></span>.
                If this wasn't you, no action is needed — the attempt was blocked automatically.
                If you'd like to let them in instead, sign out of this session below.
            </p>
            <p class="sr-only" role="status" aria-live="polite" x-show="show" x-text="'A sign-in attempt for your account was just blocked.'"></p>
        </div>

        <x-slot:footer>
            <form x-ref="logoutForm" method="POST" action="{{ route('logout') }}" class="hidden" data-no-loading>
                @csrf
                <input type="hidden" name="reason" value="single_session_takeover">
            </form>
            <button type="button" @click="show = false" class="btn btn-secondary flex-1 justify-center">Dismiss</button>
            <button type="button" @click="$refs.logoutForm.submit()" class="btn btn-primary flex-1 justify-center">Log out here</button>
        </x-slot:footer>
    </x-modal>
</div>
@endpersist
@endauth
