{{--
    Demo-only reader switcher. .NET counterpart: Pages/Shared/_LoginPartial.cshtml,
    which shows the signed-in user and the sign-in/sign-out links. Version 1 of this
    migration has no authentication (see DECISIONS.md), so this stands in for it and
    makes the per-user behaviour visible: switch reader, and the library, the stats
    and the edit permissions all change with it.
--}}
@php($readers = app(App\Services\CurrentUser::class)->selectable())
@php($current = app(App\Services\CurrentUser::class)->get())

@if ($readers->isNotEmpty())
    <form class="rl-demo" method="post" action="{{ route('demo-user.update') }}">
        @csrf
        <label for="demo-user">Reading as</label>
        <select id="demo-user" name="user_id" onchange="this.form.submit()">
            @foreach ($readers as $reader)
                <option value="{{ $reader->id }}" @selected($current?->is($reader))>{{ $reader->name }}</option>
            @endforeach
        </select>
        <noscript><button class="rl-btn rl-btn-sm rl-btn-outline" type="submit">Switch</button></noscript>
    </form>
@endif
