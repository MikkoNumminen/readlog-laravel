@extends('layouts.app', ['title' => 'Error'])

@section('content')
<div class="rl-narrow">
    <h1 class="rl-page-title rl-error">Something went wrong.</h1>
    <p>An error occurred while processing your request.</p>
    <p><a href="{{ route('feed') }}">Return home</a></p>
</div>
@endsection
