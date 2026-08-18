{{-- .NET counterpart: Pages/Index.cshtml --}}
@extends('layouts.app', ['title' => 'Recently Read'])

@section('content')
<div class="rl-narrow">
    <h1 class="rl-page-title">Recently Read</h1>
    <p class="rl-muted">See what people are reading.</p>

    @if ($reads->isEmpty())
        <p class="rl-muted rl-center">No books logged yet. Be the first!</p>
    @else
        <div class="rl-stack">
            @foreach ($reads as $read)
                <a class="rl-card rl-row" style="align-items: flex-start"
                   href="{{ route('book.show', array_filter([
                       'title' => $read->title,
                       'author' => $read->author,
                       'cover' => $read->coverUrl,
                   ])) }}">
                    @include('partials.cover', ['url' => $read->coverUrl, 'size' => 'md'])
                    <div>
                        <h2 class="rl-card-title">{{ $read->title }}</h2>
                        @if (filled($read->author))
                            <p class="rl-muted rl-small">{{ $read->author }}</p>
                        @endif
                        <div class="rl-row">
                            <span class="rl-badge">{{ $read->format->icon() }} {{ $read->format->label() }}</span>
                            <span class="rl-muted rl-small">{{ $read->createdAt->format('M j, Y') }}</span>
                        </div>
                        @include('partials.stars', ['rating' => $read->rating])
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
