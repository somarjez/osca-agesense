@extends('layouts.app')
@section('page-title', 'Quality of Life Survey')
@section('page-subtitle', $senior->full_name . ' · OSCA ID: ' . $senior->osca_id)

@section('content')
<livewire:surveys.qol-survey-form
    :senior-id="$senior->id"
    :survey-id="$surveyId ?? null" />
@endsection
