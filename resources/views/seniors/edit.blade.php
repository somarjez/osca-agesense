{{-- resources/views/seniors/edit.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Edit Profile')
@section('page-subtitle', $senior->full_name . ' · ' . $senior->osca_id)

@section('content')
<div class="space-y-5">
    <div class="flex items-center gap-3">
        <a href="{{ route('seniors.show', $senior) }}" class="btn btn-ghost gap-1.5 pl-1.5">
            <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to profile
        </a>
    </div>
    <livewire:surveys.profile-survey :senior-id="$senior->id" />
</div>
@endsection
