@props(['type' => null, 'message' => null])

@php
    // Auto-detect from session if not passed as props
    if (!$type) {
        if (session()->has('success')) { $type = 'success'; }
        elseif (session()->has('error'))   { $type = 'error'; }
        elseif (session()->has('warning')) { $type = 'warning'; }
        elseif (session()->has('info'))    { $type = 'info'; }
    }
    if (!$message) {
        $message = session('success') ?? session('error') ?? session('warning') ?? session('info');
    }
    $styles = [
        'success' => 'bg-low-50 border-low-200 text-low-800 dark:bg-low-900/30 dark:border-low-700 dark:text-low-200',
        'error'   => 'bg-high-50 border-high-200 text-high-800 dark:bg-high-900/30 dark:border-high-700 dark:text-high-200',
        'warning' => 'bg-moderate-50 border-moderate-200 text-moderate-800 dark:bg-moderate-900/30 dark:border-moderate-700 dark:text-moderate-200',
        'info'    => 'bg-info-50 border-info-200 text-info-800 dark:bg-info-900/30 dark:border-info-700 dark:text-info-200',
    ];
    $iconPaths = [
        'success' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'error'   => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
        'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
        'info'    => 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
    ];
@endphp

@if ($message && $type)
<div
    x-data="{ show: true }"
    x-show="show"
    x-init="setTimeout(() => show = false, 5000)"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    class="fixed bottom-5 right-5 z-50 flex items-start gap-3 max-w-sm w-full rounded-2xl border px-4 py-3 shadow-lg {{ $styles[$type] ?? $styles['info'] }}"
    x-cloak
>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
         stroke-width="1.5" stroke="currentColor"
         class="w-5 h-5 flex-shrink-0 mt-0.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPaths[$type] ?? $iconPaths['info'] }}" />
    </svg>
    <p class="text-sm leading-snug flex-1">{{ $message }}</p>
    <button @click="show = false"
            class="flex-shrink-0 opacity-50 hover:opacity-100 transition-opacity ml-1"
            aria-label="Dismiss notification">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke-width="2" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </button>
</div>
@endif
