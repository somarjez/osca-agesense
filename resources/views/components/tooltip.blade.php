@props(['text' => '', 'position' => 'top', 'width' => 'w-56'])
<span class="relative inline-flex overflow-visible" x-data="{ show: false }" @mouseenter="show=true" @mouseleave="show=false">
    <span>{{ $slot }}</span>
    <span x-show="show" x-cloak x-transition.opacity.duration.150ms
          class="pointer-events-none absolute z-[60] {{ $width }} rounded-lg border border-paper-rule bg-white shadow-lg text-[11.5px] text-ink-700 leading-relaxed px-3 py-2.5 whitespace-normal
                 dark:bg-[#1e2822] dark:border-[#2b3530] dark:text-[#c8c4bc]
                 @if($position === 'top')       bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2
                 @elseif($position === 'bottom') top-[calc(100%+6px)]   left-1/2 -translate-x-1/2
                 @elseif($position === 'left')   right-[calc(100%+6px)] top-1/2  -translate-y-1/2
                 @else                           left-[calc(100%+6px)]  top-1/2  -translate-y-1/2
                 @endif">
        {!! $text !!}
    </span>
</span>
