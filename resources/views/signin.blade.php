{{-- .NET counterpart: Pages/SignIn.cshtml, minus the local account form. --}}
@extends('layouts.app', ['title' => 'Sign in'])

@section('content')
<div class="rl-narrow">
    <h1 class="rl-page-title">Sign in</h1>

    @include('partials.errors')

    @if ($enabled)
        <p>Sign in with Google to keep your own reading log. Browsing needs no account.</p>
        <p>
            <a class="rl-btn" href="{{ route('signin.google') }}" rel="nofollow">Continue with Google</a>
        </p>
        <p class="rl-muted rl-small">
            ReadLog stores the name, e-mail address and profile picture Google gives it, and
            nothing else. Your reading stays private unless you ask for it to be public.
        </p>
    @else
        <p class="rl-muted rl-notice">
            Signing in is not configured on this instance, so everything here is read-only.
            The app runs the same way; it just has no accounts. See README.md.
        </p>
    @endif
</div>
@endsection
