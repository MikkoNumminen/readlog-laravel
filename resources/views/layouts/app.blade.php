{{--
    .NET counterpart: Pages/Shared/_Layout.cshtml.

    Blade and Razor are the same idea with different punctuation. `{{ $x }}` is
    Razor's `@x` and escapes by default; `{!! $x !!}` is `@Html.Raw`. `@yield` plus
    `@section` is `@RenderBody` plus `@RenderSectionAsync`. `@include` is `<partial>`.
    The one that has no Razor counterpart is `@csrf`: ASP.NET Core injects the
    antiforgery token into every form tag helper automatically, whereas Laravel makes
    you write it, and a form without it fails with a 419 rather than silently.
--}}
@php
    // Highlight the nav item for the current page and its sub-pages, so /library/3/edit
    // still lights up Library. .NET does this by comparing RouteData's "page" value.
    $section = fn (string $prefix) => request()->routeIs($prefix);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trim($title ?? '') !== '' ? $title.' · ReadLog' : 'ReadLog' }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>📚</text></svg>">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
    <a class="rl-skip" href="#main">Skip to content</a>

    <header class="rl-navbar">
        <div class="rl-container">
            <a class="rl-brand" href="{{ route('feed') }}">📚 ReadLog</a>

            <ul class="rl-nav">
                <li><a href="{{ route('feed') }}" @if ($section('feed')) aria-current="page" @endif>Home</a></li>
                <li><a href="{{ route('library.index') }}" @if ($section('library.*') || $section('entries.*')) aria-current="page" @endif>Library</a></li>
                <li><a href="{{ route('log.create') }}" @if ($section('log.*')) aria-current="page" @endif>Log a book</a></li>
                <li><a href="{{ route('account.show') }}" @if ($section('account.*')) aria-current="page" @endif>Account</a></li>
            </ul>

            @include('partials.demo-user')
        </div>
    </header>

    <div class="rl-container rl-main">
        <main id="main">
            @if (session('notice'))
                <div class="rl-notice" role="status">{{ session('notice') }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    <footer class="rl-footer">
        <div class="rl-container">
            ReadLog, track the books you&#39;ve read.
            A Laravel migration of <a href="https://github.com/MikkoNumminen/readlog-dotnet">readlog-dotnet</a>.
        </div>
    </footer>

    <script src="{{ asset('js/site.js') }}"></script>
</body>
</html>
