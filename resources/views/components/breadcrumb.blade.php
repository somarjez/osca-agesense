@props(['links' => []])
@if (count($links) > 1)
<nav aria-label="Breadcrumb" class="mb-3">
    <ol class="flex items-center gap-1 text-[12.5px]">
        @foreach ($links as $i => $link)
            @if ($i < count($links) - 1)
                <li>
                    <a href="{{ $link['href'] ?? '#' }}"
                       class="text-ink-400 hover:text-ink-700 dark:text-ink-500 dark:hover:text-ink-300 transition-colors">
                        {{ $link['label'] }}
                    </a>
                </li>
                <li aria-hidden="true">
                    <span class="text-ink-300 dark:text-ink-600 px-0.5">/</span>
                </li>
            @else
                <li aria-current="page"
                    class="text-ink-700 dark:text-ink-300 font-medium truncate max-w-[200px]">
                    {{ $link['label'] }}
                </li>
            @endif
        @endforeach
    </ol>
</nav>
@endif
