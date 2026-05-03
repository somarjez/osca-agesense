@props(['title' => null, 'sub' => null, 'noPadding' => false])
<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || $sub || isset($actions))
    <div class="card-head">
        <div>
            @if ($title)<div class="card-title">{{ $title }}</div>@endif
            @if ($sub)<div class="card-sub">{{ $sub }}</div>@endif
        </div>
        @isset($actions)
            <div class="flex items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
    @endif
    @if ($noPadding)
        <div>{{ $slot }}</div>
    @else
        <div class="card-body">{{ $slot }}</div>
    @endif
</div>
