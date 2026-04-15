{{-- resources/views/seniors/edit.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Edit Senior Profile')
@section('page-subtitle', $senior->full_name . ' · ' . $senior->osca_id)

@section('content')
<livewire:surveys.profile-survey :senior-id="$senior->id" />
@endsection
