@props(['text' => '', 'position' => 'top', 'width' => 'w-56'])
{{-- Hover tooltip. The panel is moved to <body> and positioned `fixed` on hover
     (see hoverTip in resources/js/app.js) so it floats above the app shell
     instead of being clipped by the scrollable main content / sidebar. Uses a
     plain display toggle for reliability under Livewire's bundled Alpine. --}}
<span class="relative inline-flex" x-data="hoverTip" @mouseenter="show()" @mouseleave="hide()">
    <span x-ref="trigger" class="inline-flex">{{ $slot }}</span>
    <div x-ref="panel" data-pos="{{ $position }}" style="display:none"
         class="fixed z-[200] {{ $width }} rounded-lg border border-paper-rule bg-white shadow-lg text-[11.5px] text-ink-700 leading-relaxed px-3 py-2.5 whitespace-normal pointer-events-none
                dark:bg-[#1e2822] dark:border-[#2b3530] dark:text-[#c8c4bc]">
        {!! $text !!}
    </div>
</span>
