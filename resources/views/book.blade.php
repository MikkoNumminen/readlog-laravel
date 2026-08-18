{{-- .NET counterpart: Pages/Book.cshtml --}}
@extends('layouts.app', ['title' => $title])

@section('content')
<div class="rl-narrow">
    <p><a href="{{ route('feed') }}">&larr; Back to feed</a></p>
    <h1 class="rl-page-title">{{ $title }}</h1>

    @if ($details === null)
        <p class="rl-muted">No details available for this book.</p>
    @else
        @php($cover = filled($details->coverUrl) ? $details->coverUrl : $fallbackCoverUrl)
        <div class="rl-row" style="align-items: flex-start; margin-bottom: 1rem">
            @include('partials.cover', ['url' => $cover, 'size' => 'lg'])
            <div>
                @if (count($details->authors) > 0)
                    <p class="rl-strong">{{ implode(', ', $details->authors) }}</p>
                @endif
                @if (filled($details->publishedDate))
                    <p class="rl-muted rl-small">Published: {{ $details->publishedDate }}</p>
                @endif
                @if (filled($details->publisher))
                    <p class="rl-muted rl-small">Publisher: {{ $details->publisher }}</p>
                @endif
                @if (! is_null($details->pageCount))
                    <p class="rl-muted rl-small">{{ $details->pageCount }} pages</p>
                @endif
                @if (filled($details->language))
                    <p class="rl-muted rl-small">Language: {{ Str::upper($details->language) }}</p>
                @endif
            </div>
        </div>

        @if (count($details->categories) > 0)
            <div class="rl-row" style="margin-bottom: 1rem">
                @foreach ($details->categories as $category)
                    <span class="rl-badge">{{ $category }}</span>
                @endforeach
            </div>
        @endif

        @if ($safeDescriptionHtml !== null)
            {{-- Already run through the sanitiser in the controller. This is the one
                 place in the app that renders third-party HTML unescaped, which is
                 why it is the one place using {!! !!} rather than {{ }}. --}}
            <div class="rl-description">{!! $safeDescriptionHtml !!}</div>
        @endif

        @if ($safeInfoLink !== null)
            <p style="margin-top: 1rem">
                <a href="{{ $safeInfoLink }}" target="_blank" rel="noopener noreferrer">More on Google Books &#8599;</a>
            </p>
        @endif
    @endif
</div>
@endsection
