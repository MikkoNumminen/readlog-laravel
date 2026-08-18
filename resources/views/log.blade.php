{{-- .NET counterpart: Pages/Log.cshtml --}}
@extends('layouts.app', ['title' => 'Log a book'])

@section('content')
<div class="rl-narrow">
    <h1 class="rl-page-title">Log a book</h1>

    @if (! $hasSelection)
        {{-- Stage one: find the book. --}}
        <form method="get" role="search">
            <div class="rl-field">
                <label class="rl-label" for="title">Book title</label>
                <input class="rl-input" id="title" name="title" value="{{ $title }}" autofocus>
            </div>
            <div class="rl-field">
                <label class="rl-label" for="author">Author (optional)</label>
                <input class="rl-input" id="author" name="author" value="{{ $author }}">
            </div>
            <button class="rl-btn" type="submit">Search</button>
        </form>

        @if ($searched)
            @if ($results->isEmpty())
                <p class="rl-muted" style="margin-top: 1rem">No books found.</p>
            @else
                <div class="rl-list" style="margin-top: 1rem">
                    @foreach ($results as $result)
                        <a href="{{ route('log.create', array_filter([
                            'olid' => $result->openLibraryId,
                            'sel_title' => $result->title,
                            'sel_author' => $result->author,
                            'cover' => $result->coverUrl,
                            'pages' => $result->pageCount,
                            'year' => $result->firstPublishYear,
                        ], fn ($value) => ! is_null($value) && $value !== '')) }}">
                            @include('partials.cover', ['url' => $result->coverUrl, 'size' => 'sm'])
                            <span>
                                <span class="rl-strong">
                                    {{ filled($result->subtitle) ? $result->title.' · '.$result->subtitle : $result->title }}
                                </span>
                                <span class="rl-muted rl-small" style="display: block">
                                    {{ implode(' · ', array_filter([$result->author, $result->firstPublishYear])) }}
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- The manual-add fallback is offered whether or not there were results:
                 the providers return irrelevant-but-nonzero hits for niche titles, and
                 hiding the option behind an empty result list would strand them. --}}
            @if (filled($title) && $manualId !== null)
                @if ($results->isNotEmpty())
                    <p class="rl-muted rl-small" style="margin-bottom: 0.25rem">Not the book you are looking for?</p>
                @endif
                <a class="rl-btn rl-btn-outline rl-btn-sm" style="margin-top: 0.5rem"
                   href="{{ route('log.create', array_filter([
                       'olid' => $manualId,
                       'sel_title' => $title,
                       'sel_author' => $author,
                   ])) }}">Add &quot;{{ $title }}&quot; manually</a>
            @endif
        @endif
    @else
        {{-- Stage two: the book is chosen, fill in how and when it was read. --}}
        @include('partials.errors')

        <form method="post" action="{{ route('log.store') }}">
            @csrf

            {{-- Catalogue metadata rides along in hidden fields, exactly as in the
                 source. It is only used if this book is not already in the catalogue;
                 an existing open_library_id keeps the first logger's metadata. --}}
            <input type="hidden" name="open_library_id" value="{{ old('open_library_id', $selection['open_library_id']) }}">
            <input type="hidden" name="cover_url" value="{{ old('cover_url', $selection['cover_url']) }}">
            <input type="hidden" name="page_count" value="{{ old('page_count', $selection['page_count']) }}">
            <input type="hidden" name="first_publish_year" value="{{ old('first_publish_year', $selection['first_publish_year']) }}">

            <div class="rl-card rl-row" style="align-items: flex-start; margin-bottom: 1rem">
                @include('partials.cover', ['url' => old('cover_url', $selection['cover_url']), 'size' => 'md'])
                <div style="flex: 1 1 0; min-width: 0">
                    <div class="rl-field">
                        <label class="rl-label" for="book-title">Title</label>
                        <input class="rl-input" id="book-title" name="title" value="{{ old('title', $selection['title']) }}">
                        @error('title') <div class="rl-error rl-small">{{ $message }}</div> @enderror
                    </div>
                    <div class="rl-field">
                        <label class="rl-label" for="book-author">Author</label>
                        <input class="rl-input" id="book-author" name="author" value="{{ old('author', $selection['author']) }}">
                        @error('author') <div class="rl-error rl-small">{{ $message }}</div> @enderror
                    </div>
                    <a class="rl-small" href="{{ route('log.create') }}">Change book</a>
                </div>
            </div>

            <fieldset class="rl-fieldset">
                <legend class="rl-legend">Format</legend>
                <div class="rl-segmented">
                    @foreach (App\Enums\Format::cases() as $format)
                        <input type="radio" name="format" id="fmt-{{ $format->value }}" value="{{ $format->value }}"
                               @checked(old('format', $selection['format']->value) === $format->value)>
                        <label for="fmt-{{ $format->value }}">{{ $format->icon() }} {{ $format->label() }}</label>
                    @endforeach
                </div>
                @error('format') <div class="rl-error rl-small">{{ $message }}</div> @enderror
            </fieldset>

            <div class="rl-field">
                <label class="rl-label" for="finished_at">Finished on</label>
                <input class="rl-input" type="date" id="finished_at" name="finished_at"
                       value="{{ old('finished_at', $selection['finished_at']) }}">
                @error('finished_at') <div class="rl-error rl-small">{{ $message }}</div> @enderror
            </div>

            <div class="rl-field">
                <label class="rl-label" for="rating">Your rating</label>
                <select class="rl-select" id="rating" name="rating">
                    <option value="">No rating</option>
                    @foreach (range(1, 5) as $value)
                        <option value="{{ $value }}" @selected((string) old('rating', $selection['rating']) === (string) $value)>
                            {{ str_repeat('★', $value) }} {{ $value }}
                        </option>
                    @endforeach
                </select>
                @error('rating') <div class="rl-error rl-small">{{ $message }}</div> @enderror
            </div>

            <button class="rl-btn rl-btn-block" type="submit">Save to library</button>
        </form>
    @endif
</div>
@endsection
