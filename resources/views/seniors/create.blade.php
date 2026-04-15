{{-- resources/views/seniors/create.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Register New Senior Citizen')
@section('page-subtitle', 'Complete the OSCA profile survey form')

@section('content')
<livewire:surveys.profile-survey />
@endsection
