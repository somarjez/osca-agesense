@props([
    'show',                                  // REQUIRED: assignable Alpine expression (e.g. "deleteOpen")
    'title'        => 'Are you sure?',
    'confirm'      => null,                   // Alpine expression run on confirm (e.g. "$refs.deleteForm.submit()")
    'confirmLabel' => 'Confirm',
    'cancelLabel'  => 'Cancel',
    'tone'         => 'danger',               // danger | primary
    'icon'         => 'heroicon-o-exclamation-triangle',
])
@php
    $btnClass = $tone === 'danger' ? 'btn-danger' : 'btn-primary';
    $iconWrap = $tone === 'danger'
        ? 'bg-critical-50 dark:bg-critical-900/30 text-critical-700 dark:text-critical-300'
        : 'bg-forest-50 dark:bg-forest-900/40 text-forest-700 dark:text-forest-300';
@endphp

{{-- Destructive/primary confirmation dialog built on <x-modal>. The body slot holds
     the explanation of what will happen. Pass a `confirm` Alpine expression for the
     primary action, or supply your own button(s) via the `action` slot. --}}
<x-modal show="{{ $show }}" max-width="max-w-md" :closeable="true">
    <div class="flex flex-col items-center text-center">
        <div class="w-12 h-12 rounded-2xl grid place-items-center {{ $iconWrap }}">
            <x-dynamic-component :component="$icon" class="w-6 h-6" aria-hidden="true" />
        </div>
        <h2 class="card-title mt-3.5 mb-1.5">{{ $title }}</h2>
        <div class="w-full text-[13px] text-ink-600 dark:text-[#9aada5] leading-relaxed">{{ $slot }}</div>
    </div>

    <x-slot:footer>
        <button type="button" @click="{{ $show }} = false" class="btn btn-secondary flex-1 justify-center">{{ $cancelLabel }}</button>
        @isset($action)
            {{ $action }}
        @elseif($confirm)
            <button type="button" @click="{{ $confirm }}" class="btn {{ $btnClass }} flex-1 justify-center">{{ $confirmLabel }}</button>
        @endisset
    </x-slot:footer>
</x-modal>
