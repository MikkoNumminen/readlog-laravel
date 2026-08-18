{{--
    Demo-only reader switcher. .NET counterpart: Pages/Shared/_LoginPartial.cshtml,
    which shows the signed-in user and the sign-in and sign-out links. Version 1 of
    this migration has no authentication (see DECISIONS.md), so this stands in for it
    and makes the per-user behaviour visible: switch reader, and the library, the
    stats and the edit permissions all change with it.

    The select has no inline onchange. public/js/site.js binds it instead, because
    the Content-Security-Policy this app sends uses a strict script-src 'self' and an
    inline handler would require loosening it to 'unsafe-inline'.
--}}
@php($readers = $demoReaders ?? collect())
@php($current = $demoReader ?? null)

@if ($readers->isNotEmpty())
    <form class="rl-demo" method="post" action="{{ route('demo-user.update') }}" data-auto-submit>
        @csrf
        <label for="demo-user">Reading as</label>
        <select id="demo-user" name="user_id">
            @foreach ($readers as $reader)
                <option value="{{ $reader->id }}" @selected($current?->is($reader))>{{ $reader->name }}</option>
            @endforeach
        </select>
        <noscript><button class="rl-btn rl-btn-sm rl-btn-outline" type="submit">Switch</button></noscript>
    </form>
@endif
