{{-- One entry as a compact row: cover, title, author, format badge, finished date,
     rating. Used by both search panels on the library page.
     Expects $match (App\Support\LibraryEntry). --}}
<div class="rl-card rl-row">
    @include('partials.cover', ['url' => $match->book->coverUrl, 'size' => 'sm'])
    <div>
        <div class="rl-strong">{{ $match->book->title }}</div>
        @if (filled($match->book->author))
            <div class="rl-muted rl-small">{{ $match->book->author }}</div>
        @endif
        <div class="rl-row">
            <span class="rl-badge">{{ $match->format->icon() }} {{ $match->format->label() }}</span>
            <span class="rl-muted rl-small">{{ $match->finishedAt->format('M j, Y') }}</span>
            @if ($match->rating !== null)
                <span class="rl-muted rl-small">{{ $match->rating }}/5</span>
            @endif
        </div>
    </div>
</div>
