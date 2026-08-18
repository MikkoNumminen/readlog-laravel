<?php

use App\Services\BookDetailsService;
use App\Services\BookSearchService;

/*
| The only tests in this suite that touch the network, and they are off by default.
|
| Everything else fakes its outbound requests, which proves the code does what it is
| told but proves nothing about whether the faked JSON still looks like what the
| providers actually send. These fill that gap. They run only when
| BOOK_SEARCH_LIVE_TESTS=true, so CI and a normal `vendor/bin/pest` never reach
| openlibrary.org.
|
| .NET counterpart: none. readlog-dotnet stubs its HttpMessageHandler everywhere and
| has no live checks at all. The brief asked for real calls behind a config flag,
| and this is that flag.
|
| Run them with:
|
|     BOOK_SEARCH_LIVE_TESTS=true vendor/bin/pest --filter=LiveProvider
|
| They assert response *shape*, never specific data. Open Library's ranking changes,
| covers come and go, and a test that depends on Dune being the top hit would fail
| for reasons that have nothing to do with this code.
*/

pest()->group('live');

beforeEach(function () {
    if (! config('services.book_search.live_tests')) {
        test()->markTestSkipped('Live provider tests are off. Set BOOK_SEARCH_LIVE_TESTS=true to run them.');
    }
});

it('gets usable results from the real Open Library', function () {
    config()->set('services.google_books.api_key', null); // Open Library only

    $results = app(BookSearchService::class)->search('dune frank herbert');

    expect($results)->not->toBeEmpty();

    $first = $results->first();

    expect($first->openLibraryId)->toStartWith('/works/OL')
        ->and($first->title)->not->toBe('');

    // Whatever covers came back must be the URL shape the app builds.
    $results->filter(fn ($book) => $book->coverUrl !== null)
        ->each(fn ($book) => expect($book->coverUrl)->toStartWith('https://covers.openlibrary.org/b/id/'));
});

it('still merges when both real providers answer', function () {
    if (config('services.google_books.api_key') === null) {
        test()->markTestSkipped('No GOOGLE_BOOKS_API_KEY configured.');
    }

    $results = app(BookSearchService::class)->search('the hobbit tolkien');

    expect($results)->not->toBeEmpty();

    // The de-dup key must be unique across the merged list, which is the one
    // invariant the merge is responsible for.
    $keys = $results->map(fn ($book) => strtolower(preg_replace('/[^a-z0-9]/i', '', $book->title.($book->author ?? ''))));

    expect($keys->unique()->count())->toBe($keys->count());
});

it('gets real details from Google Books', function () {
    if (config('services.google_books.api_key') === null) {
        test()->markTestSkipped('No GOOGLE_BOOKS_API_KEY configured.');
    }

    $details = app(BookDetailsService::class)->getDetails('Dune', 'Frank Herbert');

    expect($details)->not->toBeNull()
        ->and($details->title)->not->toBe('')
        ->and($details->coverUrl)->toStartWith('https://'); // never http
});
