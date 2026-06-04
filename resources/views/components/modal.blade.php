@props([
    'show',                  // REQUIRED: an assignable Alpine expression that controls visibility,
                             //           e.g. show="deleteOpen" (declared on a parent x-data)
    'title'    => null,
    'ariaLabel' => null,
    'maxWidth' => 'max-w-sm',
    'closeable' => true,
])
@php $titleId = 'modal-' . \Illuminate\Support\Str::random(6); @endphp

{{-- Reusable accessible modal (forest/ink). Backed by an Alpine boolean owned by the
     parent scope. position:fixed escapes overflow clipping (no teleport needed, which
     keeps it Livewire-morph safe). Bakes in: role/aria-modal/aria-labelledby, ESC to
     close, backdrop-click to close, focus-into-dialog on open, body scroll-lock, and
     reduced-motion-aware transitions (the global prefers-reduced-motion rule makes
     these instant for users who ask for it). --}}
<div x-show="{{ $show }}"
     x-cloak
     role="dialog"
     aria-modal="true"
     @if($title) aria-labelledby="{{ $titleId }}" @endif
     @if($ariaLabel) aria-label="{{ $ariaLabel }}" @endif
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     @if($closeable) @keydown.escape.window="{{ $show }} = false" @endif
     x-effect="document.body.classList.toggle('overflow-hidden', !!({{ $show }}))"
     x-transition.opacity.duration.150ms>

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"
         @if($closeable) @click="{{ $show }} = false" @endif
         aria-hidden="true"></div>

    {{-- Panel --}}
    <div class="relative card {{ $maxWidth }} w-full shadow-2xl"
         tabindex="-1"
         x-show="{{ $show }}"
         x-effect="if ({{ $show }}) $nextTick(() => $el.focus())"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95">

        @if($title)
        <div class="card-head">
            <div class="card-title" id="{{ $titleId }}">{{ $title }}</div>
            @if($closeable)
            <button type="button" @click="{{ $show }} = false"
                    class="btn btn-ghost p-1.5 -mr-1" aria-label="Close dialog">
                <x-heroicon-o-x-mark class="w-4 h-4" aria-hidden="true" />
            </button>
            @endif
        </div>
        @endif

        <div class="card-body">
            {{ $slot }}
            @isset($footer)
            <div class="flex flex-wrap gap-2 justify-end mt-5">
                {{ $footer }}
            </div>
            @endisset
        </div>
    </div>
</div>
