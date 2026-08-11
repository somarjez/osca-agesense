@extends('layouts.error')

@section('title', 'Page Expired · AgeSense')
@section('code', '419')
@section('heading', 'Page Expired')
@section('message')
    Your session expired for security reasons. Please refresh the page and try again.
@endsection

@section('cta')
    <a href="{{ route('login') }}"
       class="btn btn-primary inline-flex mt-8 px-5 py-2.5 text-[13.5px]">
        Sign in again
    </a>
@endsection
