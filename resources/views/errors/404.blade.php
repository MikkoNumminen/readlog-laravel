{{--
    .NET counterpart: Pages/Error.cshtml, which the source reaches through
    UseStatusCodePagesWithReExecute("/Error", "?code={0}") and branches on the code.
    Laravel resolves resources/views/errors/{status}.blade.php by convention
    instead, so the branch becomes two files.
--}}
@extends('layouts.app', ['title' => 'Page not found'])

@section('content')
<div class="rl-narrow">
    <h1 class="rl-page-title rl-error">Page not found</h1>
    <p>We could not find the page you were looking for.</p>
    <p><a href="{{ route('feed') }}">Return home</a></p>
</div>
@endsection
