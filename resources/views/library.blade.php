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
                        @include('partials.library-match', ['match' => $result])
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    @if ($askEnabled)
        {{-- No .NET counterpart. A question in plain words, answered from this
             reader's own entries by a local model (Ollama). Degrades to the title
             search above with a notice when Ollama is not reachable. --}}
        <form method="get" role="search" class="rl-field">
            <label class="rl-label" for="ask">Ask your library</label>
            <input class="rl-input" type="search" id="ask" name="ask" value="{{ $ask }}"
                   placeholder="e.g. audiobooks I rated 5 last year, or the one about a desert planet">
            <p class="rl-muted rl-small">Answered by a model running on this machine, from your entries only. Nothing leaves it.</p>
        </form>

        <div role="status" aria-live="polite">
            @if ($askResult !== null)
                @if ($askResult->unavailable)
                    <p class="rl-muted rl-notice">
                        AI search is unavailable: {{ $askResult->reason }} Showing title matches instead.
                    </p>
                    @if ($askFallback->isEmpty())
                        <p class="rl-muted">Not in your library.</p>
                    @else
                        <div class="rl-stack" style="margin-bottom: 1.5rem">
                            @foreach ($askFallback as $result)
                                @include('partials.library-match', ['match' => $result])
                            @endforeach
                        </div>
                    @endif
                @else
                    @if ($askResult->answer !== null)
                        <p class="rl-found">{{ $askResult->answer }}</p>
                    @endif
                    @if ($askResult->notice !== null)
                        <p class="rl-muted rl-notice">{{ $askResult->notice }}</p>
                    @endif
                    @if ($askResult->applied !== [])
                        <p class="rl-muted rl-small">Looked at: {{ implode(', ', $askResult->applied) }}.</p>
                    @endif
                    @if ($askResult->shown()->isNotEmpty())
                        <p class="rl-muted rl-small">{{ $askResult->cited->isNotEmpty() ? 'Based on:' : 'Closest entries:' }}</p>
                        <div class="rl-stack" style="margin-bottom: 1rem">
                            @foreach ($askResult->shown() as $result)
                                @include('partials.library-match', ['match' => $result])
                            @endforeach
                        </div>
                    @endif
                    @if ($askResult->others()->isNotEmpty())
                        <details style="margin-bottom: 1.5rem">
                            <summary class="rl-muted rl-small">Other close entries the model saw ({{ $askResult->others()->count() }})</summary>
                            <div class="rl-stack" style="margin-top: 0.5rem">
                                @foreach ($askResult->others() as $result)
                                    @include('partials.library-match', ['match' => $result])
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endif
            @endif
        </div>
    @endif

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
