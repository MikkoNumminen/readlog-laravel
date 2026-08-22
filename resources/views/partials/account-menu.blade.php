{{--
    Who you are, and the way in or out.

    .NET counterpart: Pages/Shared/_LoginPartial.cshtml, which shows the signed-in
    user's display name with a sign-out button, or sign-in and register links. This
    one has no register link because there is no local account to register; Google
    is the only door. It replaced the demo reader switcher that stood in for all of
    this while the port had no authentication.
--}}
@auth
    <div class="rl-account-menu">
        @if (auth()->user()->avatar_url)
            <img class="rl-avatar" src="{{ auth()->user()->avatar_url }}" alt="" width="28" height="28" referrerpolicy="no-referrer">
        @endif
        <span class="rl-muted rl-small">{{ auth()->user()->name }}</span>
        <form method="post" action="{{ route('signout') }}">
            @csrf
            <button class="rl-btn rl-btn-sm rl-btn-outline" type="submit">Sign out</button>
        </form>
    </div>
@else
    <a class="rl-btn rl-btn-sm" href="{{ route('signin') }}">Sign in</a>
@endauth
