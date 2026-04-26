@props(['id' => 1, 'label' => null])
<span class="badge badge-neutral">
    <span class="cluster-swatch cluster-swatch-{{ $id }}"></span>
    C{{ $id }}@if($label) · {{ $label }}@endif
</span>
