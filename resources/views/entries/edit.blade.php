{{-- .NET counterpart: Pages/Library/Edit.cshtml --}}
@extends('layouts.app', ['title' => 'Edit entry'])

@section('content')
<div class="rl-narrow">
    <h1 class="rl-page-title">Edit entry</h1>

    <div class="rl-row rl-muted" style="margin-bottom: 1rem">
        @include('partials.cover', ['url' => $entry->book->coverUrl, 'size' => 'sm'])
        {{-- Written as one echo rather than "entry@if (...)" on purpose. Blade only
             treats @if as a directive when it is not preceded by a word character,
             so "entry@if" compiles to the literal text "entry@if" and then the
             matching @endif blows up with a syntax error far from the cause. Razor
             has no such rule: @ transitions anywhere. --}}
        <span class="rl-small">
            Editing your entry{{ filled($entry->book->author) ? ' · '.$entry->book->author : '' }}
        </span>
    </div>

    @include('partials.errors')

    <form method="post" action="{{ route('entries.update', $entry->id) }}">
        @csrf
        {{-- HTML forms only speak GET and POST. Laravel tunnels the real verb in a
             hidden _method field; ASP.NET Core Razor Pages sidesteps the question by
             routing on named handlers (asp-page-handler) instead of on verbs. --}}
        @method('PUT')

        <div class="rl-field">
            <span class="rl-label">Title</span>
            <div class="rl-strong">{{ $entry->book->title }}</div>
            <div class="rl-muted rl-small">
                The book title comes from the shared catalogue and is not edited here.
            </div>
        </div>

        <fieldset class="rl-fieldset">
            <legend class="rl-legend">Format</legend>
            <div class="rl-segmented">
                @foreach (App\Enums\Format::cases() as $format)
                    <input type="radio" name="format" id="fmt-{{ $format->value }}" value="{{ $format->value }}"
                           @checked(old('format', $entry->format->value) === $format->value)>
                    <label for="fmt-{{ $format->value }}">{{ $format->icon() }} {{ $format->label() }}</label>
                @endforeach
            </div>
            @error('format') <div class="rl-error rl-small">{{ $message }}</div> @enderror
        </fieldset>

        <div class="rl-field">
            <label class="rl-label" for="finished_at">Finished on</label>
            <input class="rl-input" type="date" id="finished_at" name="finished_at"
                   value="{{ old('finished_at', $entry->finishedAt->toDateString()) }}">
            @error('finished_at') <div class="rl-error rl-small">{{ $message }}</div> @enderror
        </div>

        <div class="rl-field">
            <label class="rl-label" for="rating">Rating</label>
            <select class="rl-select" id="rating" name="rating">
                <option value="">No rating</option>
                @foreach (range(1, 5) as $value)
                    <option value="{{ $value }}" @selected((string) old('rating', $entry->rating) === (string) $value)>
                        {{ str_repeat('★', $value) }} {{ $value }}
                    </option>
                @endforeach
            </select>
            @error('rating') <div class="rl-error rl-small">{{ $message }}</div> @enderror
        </div>

        <div class="rl-row">
            <button class="rl-btn" type="submit">Save</button>
            <a class="rl-btn rl-btn-outline" href="{{ route('library.index') }}">Cancel</a>
        </div>
    </form>

    <form method="post" action="{{ route('entries.destroy', $entry->id) }}"
          data-confirm="Delete this entry?" style="margin-top: 1rem">
        @csrf
        @method('DELETE')
        <button class="rl-btn rl-btn-sm rl-btn-danger" type="submit">Delete entry</button>
    </form>
</div>
@endsection
