{{-- .NET counterpart: Pages/Library.cshtml --}}
@extends('layouts.app', ['title' => 'My Library'])

@section('content')
<div class="rl-medium">
    <h1 class="rl-page-title">My Library</h1>

    <form method="get" role="search" class="rl-field">
        <label class="rl-label" for="q">Have I read this?</label>
        <input class="rl-input" type="search" id="q" name="q" value="{{ $query }}"
               placeholder="Search your library…">
    </form>

    <div role="status" aria-live="polite">
        @if ($searched)
            @if ($searchResults->isEmpty())
                <p class="rl-muted">Not in your library.</p>
            @else
                <p class="rl-found">
                    Yes! Found {{ $searchResults->count() }} {{ $searchResults->count() === 1 ? 'match' : 'matches' }}:
                </p>
                <div class="rl-stack" style="margin-bottom: 1.5rem">
                    @foreach ($searchResults as $result)
                        <div class="rl-card rl-row">
                            @include('partials.cover', ['url' => $result->book->coverUrl, 'size' => 'sm'])
                            <div>
                                <div class="rl-strong">{{ $result->book->title }}</div>
                                <div class="rl-row">
                                    <span class="rl-badge">{{ $result->format->icon() }} {{ $result->format->label() }}</span>
                                    <span class="rl-muted rl-small">{{ $result->finishedAt->format('M j, Y') }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    @if ($entries->isEmpty())
        <p class="rl-muted rl-center">No books logged yet. Start by logging your first book!</p>
    @else
        <div class="rl-row" style="justify-content: flex-end; margin-bottom: 0.5rem">
            <a class="rl-btn rl-btn-sm @if ($view !== 'grid') rl-btn-outline @endif"
               href="{{ route('library.index', array_filter(['view' => 'grid', 'q' => $query])) }}">Grid</a>
            <a class="rl-btn rl-btn-sm @if ($view !== 'list') rl-btn-outline @endif"
               href="{{ route('library.index', array_filter(['view' => 'list', 'q' => $query])) }}">List</a>
        </div>

        @if ($view === 'list')
            <div class="rl-stack">
                @foreach ($entries as $entry)
                    <div class="rl-card rl-row">
                        @include('partials.cover', ['url' => $entry->book->coverUrl, 'size' => 'sm'])
                        <div style="flex: 1 1 0; min-width: 0">
                            <div class="rl-strong rl-truncate">{{ $entry->book->title }}</div>
                            @if (filled($entry->book->author))
                                <div class="rl-muted rl-small rl-truncate">{{ $entry->book->author }}</div>
                            @endif
                            @include('partials.stars', ['rating' => $entry->rating])
                        </div>
                        <span class="rl-badge">{{ $entry->format->icon() }} {{ $entry->format->label() }}</span>
                        <span class="rl-muted rl-small">{{ $entry->finishedAt->format('M j, Y') }}</span>
                        <a class="rl-btn rl-btn-sm rl-btn-outline" href="{{ route('entries.edit', $entry->id) }}">Edit</a>
                    </div>
                @endforeach
            </div>
        @else
            <div class="rl-grid">
                @foreach ($entries as $entry)
                    <a class="rl-grid-item" href="{{ route('entries.edit', $entry->id) }}">
                        @include('partials.cover', ['url' => $entry->book->coverUrl, 'size' => 'grid'])
                        <div class="rl-small rl-strong rl-clamp-2">{{ $entry->book->title }}</div>
                        @if (filled($entry->book->author))
                            <div class="rl-grid-author rl-truncate">{{ $entry->book->author }}</div>
                        @endif
                        @include('partials.stars', ['rating' => $entry->rating])
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</div>
@endsection
