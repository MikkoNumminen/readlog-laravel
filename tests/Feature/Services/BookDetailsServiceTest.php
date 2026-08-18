<?php

use App\Services\BookDetailsService;
use App\Support\BookDetails;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| Port of tests/ReadLog.Tests/Services/BookDetailsServiceTests.cs, plus the Google
| Books detail mapping cases from GoogleBooksClientTests.cs, which reach it through
| the same path.
*/

beforeEach(function () {
    config()->set('services.google_books.api_key', 'test-key');
});

function detailsService(): BookDetailsService
{
    return app(BookDetailsService::class);
}

/**
 * @param  array<string, mixed>  $volumeInfo
 */
function fakeVolume(array $volumeInfo): void
{
    Http::fake([
        'www.googleapis.com/*' => Http::response(['items' => [['id' => 'x', 'volumeInfo' => $volumeInfo]]]),
    ]);
}

it('maps the first volume', function () {
    fakeVolume([
        'title' => 'Dune',
        'authors' => ['Frank Herbert'],
        'description' => '<p>Spice.</p>',
        'categories' => ['Fiction'],
        'publisher' => 'Ace',
        'publishedDate' => '1965',
        'pageCount' => 412,
        'language' => 'en',
        'imageLinks' => ['thumbnail' => 'http://example.com/c.jpg'],
        'previewLink' => 'https://preview',
        'infoLink' => 'https://info',
    ]);

    $details = detailsService()->getDetails('Dune', 'Frank Herbert');

    expect($details)->toBeInstanceOf(BookDetails::class)
        ->and($details->title)->toBe('Dune')
        ->and($details->authors)->toBe(['Frank Herbert'])
        ->and($details->description)->toBe('<p>Spice.</p>')
        ->and($details->categories)->toBe(['Fiction'])
        ->and($details->publisher)->toBe('Ace')
        ->and($details->pageCount)->toBe(412)
        ->and($details->coverUrl)->toBe('https://example.com/c.jpg')
        ->and($details->infoLink)->toBe('https://info');
});

it('maps every author and leaves an https cover alone', function () {
    fakeVolume([
        'title' => 'Dune',
        'authors' => ['Frank Herbert', 'Brian Herbert'],
        'imageLinks' => ['thumbnail' => 'https://already.secure/cover.jpg'],
    ]);

    $details = detailsService()->getDetails('Dune', null);

    expect($details->authors)->toBe(['Frank Herbert', 'Brian Herbert'])
        ->and($details->coverUrl)->toBe('https://already.secure/cover.jpg');
});

it('returns a null cover when there are no image links', function () {
    fakeVolume(['title' => 'No cover']);

    expect(detailsService()->getDetails('No cover', null)->coverUrl)->toBeNull();
});

it('asks for exactly one result', function () {
    fakeVolume(['title' => 'Dune']);

    detailsService()->getDetails('Dune', 'Frank Herbert');

    Http::assertSent(fn ($request) => $request['maxResults'] === 1
        && $request['q'] === 'Dune Frank Herbert');
});

it('returns null when no volume matches', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);

    expect(detailsService()->getDetails('Nope', null))->toBeNull();
});

it('returns null on a non-success response', function () {
    Http::fake(['www.googleapis.com/*' => Http::response('rate limited', 429)]);

    expect(detailsService()->getDetails('Dune', null))->toBeNull();
});

it('makes no request at all without an API key', function () {
    config()->set('services.google_books.api_key', null);
    Http::fake();

    expect(detailsService()->getDetails('Dune', null))->toBeNull();

    Http::assertNothingSent();
});

it('makes no request for a blank title and no author', function () {
    Http::fake();

    expect(detailsService()->getDetails('   ', null))->toBeNull();

    Http::assertNothingSent();
});

// --- Caching ---------------------------------------------------------------

it('caches a successful lookup', function () {
    fakeVolume(['title' => 'Dune']);

    $first = detailsService()->getDetails('Dune', 'Frank Herbert');
    $second = detailsService()->getDetails('Dune', 'Frank Herbert');

    expect($first->title)->toBe($second->title);
    Http::assertSentCount(1); // the second was served from cache
});

it('does not cache a miss, so it is retried', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);

    detailsService()->getDetails('Nope', null);
    detailsService()->getDetails('Nope', null);

    Http::assertSentCount(2);
});

it('keys the cache by title and author together', function () {
    fakeVolume(['title' => 'Shared']);

    detailsService()->getDetails('Shared', 'Author One');
    detailsService()->getDetails('Shared', 'Author Two');

    Http::assertSentCount(2); // a different author is a different key
});

it('shares one cache entry across case and whitespace variants', function () {
    fakeVolume(['title' => 'Dune']);

    detailsService()->getDetails('Dune', 'Frank Herbert');
    detailsService()->getDetails('  dune  ', 'FRANK HERBERT');

    Http::assertSentCount(1);
});

it('does not confuse a title containing the key separator with another book', function () {
    // .NET keys the cache on a tuple, whose structural equality has no delimiter to
    // confuse. PHP cache keys are strings, so the parts are JSON-encoded and hashed.
    // Without that, ("a|b", null) and ("a", "b") could collide.
    fakeVolume(['title' => 'Whatever']);

    detailsService()->getDetails('a|b', null);
    detailsService()->getDetails('a', 'b');

    Http::assertSentCount(2);
});

it('survives a serialising cache store, unlike an object payload', function () {
    // Same trap as the public feed: Laravel 13 refuses to unserialize classes from
    // cache, so BookDetails is stored as an array and rebuilt on read.
    config()->set('cache.default', 'database');
    config()->set('cache.serializable_classes', false);
    Cache::clearResolvedInstances();

    fakeVolume(['title' => 'Dune', 'authors' => ['Frank Herbert'], 'pageCount' => 412]);

    $fresh = detailsService()->getDetails('Dune', 'Frank Herbert');
    $cached = detailsService()->getDetails('Dune', 'Frank Herbert');

    expect($cached)->toBeInstanceOf(BookDetails::class)
        ->and($cached->title)->toBe($fresh->title)
        ->and($cached->authors)->toBe($fresh->authors)
        ->and($cached->pageCount)->toBe($fresh->pageCount);

    Http::assertSentCount(1);
});
