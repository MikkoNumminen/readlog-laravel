{{-- .NET counterpart: Pages/Account.cshtml --}}
@extends('layouts.app', ['title' => 'Account'])

@section('content')
<div class="rl-narrow">
    <h1 class="rl-page-title">Account</h1>

    <div class="rl-card rl-row" style="margin-bottom: 1rem">
        @if (filled($imageUrl))
            <img class="rl-avatar" src="{{ $imageUrl }}" alt="" referrerpolicy="no-referrer">
        @else
            <div class="rl-avatar">{{ $initial }}</div>
        @endif
        <div>
            @if (filled($displayName))
                <div class="rl-strong">{{ $displayName }}</div>
            @endif
            <div class="rl-muted rl-small">{{ $email }}</div>
        </div>
    </div>

    <div class="rl-card">
        <h2 class="rl-card-title">Reading stats</h2>
        <div class="rl-stat">{{ $stats->totalBooks }}</div>
        <p class="rl-muted">{{ $stats->totalBooks === 1 ? 'book' : 'books' }} logged</p>

        <div class="rl-row">
            @foreach (App\Enums\Format::cases() as $format)
                @php($count = $stats->countFor($format))
                @if ($count > 0)
                    <span class="rl-badge">{{ $format->icon() }} {{ $count }} {{ $format->pluralLabel() }}</span>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endsection
