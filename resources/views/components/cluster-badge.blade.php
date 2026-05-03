@props(['id' => 1, 'label' => null])
<span class="badge badge-cluster-{{ $id }}">
    <span class="cluster-swatch cluster-swatch-{{ $id }}"></span>
    Group {{ $id }}@if($label) · {{ $label }}@endif
</span>
