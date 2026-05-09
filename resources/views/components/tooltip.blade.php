@props(['text' => '', 'position' => 'top', 'width' => 'w-56'])
<span class="relative inline-flex" x-data="{ show: false }" @mouseenter="show=true" @mouseleave="show=false">
    <span>{{ $slot }}</span>
    <span x-show="show" x-cloak x-transition.opacity.duration.150ms
          class="pointer-events-none absolute z-50 {{ $width }} rounded-lg border border-paper-rule bg-white shadow-md text-[11.5px] text-ink-700 leading-relaxed px-3 py-2.5
                 dark:bg-[#1e2822] dark:border-[#2b3530] dark:text-[#c8c4bc]
                 @if($position === 'top')    bottom-full mb-2 left-1/2 -translate-x-1/2
                 @elseif($position === 'bottom') top-full mt-2 left-1/2 -translate-x-1/2
                 @elseif($position === 'left')   right-full mr-2 top-1/2 -translate-y-1/2
                 @else                           left-full ml-2 top-1/2 -translate-y-1/2
                 @endif">
        {!! $text !!}
    </span>
</span>
