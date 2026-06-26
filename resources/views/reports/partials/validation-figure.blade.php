{{-- Reusable evaluation figure: plot served from storage + caption.
     Hides itself if the image is missing. Vars: $plot, $title, $caption (optional). --}}
<figure class="rounded-xl border border-paper-rule dark:border-[#2b3530] overflow-hidden bg-white">
    <img src="{{ route('reports.validation.plot', $plot) }}"
         alt="{{ $title }}"
         class="w-full block bg-white"
         loading="lazy"
         onerror="this.closest('figure').style.display='none'">
    <figcaption class="px-4 py-2.5 border-t border-paper-rule dark:border-[#2b3530] bg-paper-2/50 dark:bg-[#131917]">
        <span class="text-[12px] font-semibold text-ink-700 dark:text-[#c8c4bc]">{{ $title }}</span>
        @isset($caption)
            <span class="block text-[11.5px] text-ink-500 dark:text-[#8a9087] mt-0.5 leading-snug">{{ $caption }}</span>
        @endisset
    </figcaption>
</figure>
