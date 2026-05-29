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
    <div class="flex gap-3.5">
        <div class="w-9 h-9 rounded-xl grid place-items-center flex-shrink-0 {{ $iconWrap }}">
            <x-dynamic-component :component="$icon" class="w-5 h-5" aria-hidden="true" />
        </div>
        <div class="min-w-0">
            <h2 class="card-title mb-1">{{ $title }}</h2>
            <div class="text-[13px] text-ink-600 dark:text-[#9aada5] leading-relaxed">{{ $slot }}</div>
        </div>
    </div>

    <x-slot:footer>
        <button type="button" @click="{{ $show }} = false" class="btn btn-secondary">{{ $cancelLabel }}</button>
        @isset($action)
            {{ $action }}
        @elseif($confirm)
            <button type="button" @click="{{ $confirm }}" class="btn {{ $btnClass }}">{{ $confirmLabel }}</button>
        @endisset
    </x-slot:footer>
</x-modal>
