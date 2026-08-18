<?php

use App\Services\BookSearchService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
| Port of tests/ReadLog.Tests/Services/BookSearchServiceTests.cs, plus the merge
| cases the brief asks for.
|
| The .NET tests inject stub IOpenLibraryClient / IGoogleBooksClient implementations,
| which is what the interfaces exist for. There are no interfaces here, so the seam
| moved one layer out: these fake the HTTP responses with Http::fake and exercise the
| real clients underneath. That is a better test, not a worse one, because the
| provider JSON shapes are now covered by the same cases as the merge logic.
*/

const OPEN_LIBRARY_URL = 'openlibrary.org/*';

const GOOGLE_BOOKS_URL = 'www.googleapis.com/*';

beforeEach(function () {
    config()->set('services.google_books.api_key', 'test-key');
});

function searchService(): BookSearchService
{
    return app(BookSearchService::class);
}

/**
 * @param  list<array<string, mixed>>  $docs
 * @return array<string, mixed>
 */
function openLibraryDocs(array $docs): array
{
    return ['docs' => $docs];
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function googleVolumes(array $items): array
{
    return ['items' => $items];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function olDoc(string $key, string $title, array $extra = []): array
{
    return array_merge(['key' => $key, 'title' => $title], $extra);
}

/**
 * @param  array<string, mixed>  $volumeInfo
 * @return array<string, mixed>
 */
function gVolume(string $id, array $volumeInfo): array
{
    return ['id' => $id, 'volumeInfo' => $volumeInfo];
}

// --- Fan-out and ordering --------------------------------------------------

it('queries both providers and concatenates Open Library first', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL1W', 'Dune')])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([gVolume('g1', ['title' => 'Foundation'])])),
    ]);

    $results = searchService()->search('sci-fi');

    expect($results->pluck('openLibraryId')->all())->toBe(['/works/OL1W', 'google:g1']);
});

it('sends both requests in one pool rather than one after the other', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    searchService()->search('dune');

    // The .NET counterpart is Task.WhenAll over two started tasks. There is no
    // portable way to assert wall-clock concurrency, so what is asserted is that
    // both requests were actually issued with the parameters the source sends.
    Http::assertSentCount(2);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'openlibrary.org/search.json')
        && $request['q'] === 'dune'
        && $request['limit'] === 15
        && str_contains((string) $request['fields'], 'number_of_pages_median'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'googleapis.com/books/v1/volumes')
        && $request['q'] === 'dune'
        && $request['maxResults'] === 15
        && $request['key'] === 'test-key');
});

it('returns nothing for a blank query without calling anything', function () {
    Http::fake();

    expect(searchService()->search('   '))->toBeEmpty();

    Http::assertNothingSent();
});

it('skips Google Books entirely when no API key is configured', function () {
    config()->set('services.google_books.api_key', null);

    Http::fake([OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL1W', 'Dune')]))]);

    $results = searchService()->search('dune');

    expect($results)->toHaveCount(1);
    Http::assertSentCount(1);
});

// --- Tolerating a failing provider -----------------------------------------

it('still returns Google results when Open Library fails', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response('upstream exploded', 500),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([gVolume('g1', ['title' => 'Foundation'])])),
    ]);

    $results = searchService()->search('foundation');

    expect($results)->toHaveCount(1)
        ->and($results->first()->openLibraryId)->toBe('google:g1');
});

it('still returns Open Library results when Google fails', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL1W', 'Dune')])),
        GOOGLE_BOOKS_URL => Http::response('quota exceeded', 429),
    ]);

    $results = searchService()->search('dune');

    expect($results)->toHaveCount(1)
        ->and($results->first()->openLibraryId)->toBe('/works/OL1W');
});

it('returns an empty list when both providers fail', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response('down', 503),
        GOOGLE_BOOKS_URL => Http::response('down', 503),
    ]);

    expect(searchService()->search('dune'))->toBeEmpty();
});

it('survives a connection failure, not just an error status', function () {
    Http::fake([
        OPEN_LIBRARY_URL => fn () => throw new ConnectionException('dns failure'),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([gVolume('g1', ['title' => 'Foundation'])])),
    ]);

    expect(searchService()->search('foundation'))->toHaveCount(1);
});

// --- De-duplication and the merge ------------------------------------------

it('keeps the richer of two duplicates', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL1W', 'Dune')])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('g1', ['title' => 'Dune!', 'pageCount' => 412, 'imageLinks' => ['thumbnail' => 'http://c/x.jpg']]),
        ])),
    ]);

    $results = searchService()->search('dune');

    expect($results)->toHaveCount(1)
        ->and($results->first()->openLibraryId)->toBe('google:g1')
        ->and($results->first()->coverUrl)->toBe('https://c/x.jpg');
});

it('breaks a score tie in favour of Open Library', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL1W', 'Dune', ['cover_i' => 1])])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('g1', ['title' => 'Dune', 'imageLinks' => ['thumbnail' => 'https://g/x.jpg']]),
        ])),
    ]);

    $results = searchService()->search('dune');

    expect($results)->toHaveCount(1)
        ->and($results->first()->openLibraryId)->toBe('/works/OL1W');
});

it('merges conflicting metadata by richness, not by correctness', function () {
    // The case the brief singles out. Both providers describe the same book and
    // disagree about the title's punctuation, the author's form, the year and the
    // page count. Only one of them has a cover.
    //
    // What the ported algorithm does with that is worth being precise about,
    // because it is blunt: the two records collapse to one key, and the winner is
    // whichever has more non-null cover and page-count fields. There is no
    // field-level merge. The loser's page count is not adopted, its year is not
    // preferred for being more plausible, and nothing looks at which value is
    // right. The record with more metadata wins whole, and everything it does not
    // carry is simply lost.
    //
    // This is exactly what readlog-dotnet does, and what the Next.js original did
    // before it. It is ported as-is rather than improved, and it is the reason
    // "LLM-assisted merge of conflicting metadata" is parked in TODO.md.
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([
            olDoc('/works/OL893414W', 'Dune.', [
                'author_name' => ['Frank Herbert'],
                'first_publish_year' => 1965,
                'number_of_pages_median' => 412,
            ]),
        ])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('g-dune', [
                'title' => 'Dune',
                'authors' => ['Frank Herbert'],
                'publishedDate' => '1990-06-01',
                'pageCount' => 896,
                'imageLinks' => ['thumbnail' => 'http://books.google.com/dune.jpg'],
            ]),
        ])),
    ]);

    $results = searchService()->search('dune frank herbert');
    $merged = $results->sole();

    // Normalising away the full stop is what made them one record.
    expect($merged->openLibraryId)->toBe('google:g-dune')  // score 2 beats score 1
        ->and($merged->title)->toBe('Dune')
        ->and($merged->coverUrl)->toBe('https://books.google.com/dune.jpg')
        // The winner's values are taken whole. 1965 was almost certainly the better
        // first-publish year, and it is gone.
        ->and($merged->firstPublishYear)->toBe(1990)
        ->and($merged->pageCount)->toBe(896);
});

it('keeps the Open Library record when the conflicting Google one is thinner', function () {
    // The mirror image of the previous case, to show the tie-break is about metadata
    // count and nothing else.
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([
            olDoc('/works/OL893414W', 'Dune', [
                'author_name' => ['Frank Herbert'],
                'first_publish_year' => 1965,
                'number_of_pages_median' => 412,
                'cover_i' => 11481354,
            ]),
        ])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('g-dune', ['title' => 'Dune', 'authors' => ['Frank Herbert'], 'publishedDate' => '1990']),
        ])),
    ]);

    $merged = searchService()->search('dune')->sole();

    expect($merged->openLibraryId)->toBe('/works/OL893414W')
        ->and($merged->firstPublishYear)->toBe(1965)
        ->and($merged->pageCount)->toBe(412);
});

it('treats a different author as a different book', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([
            olDoc('/works/OL1W', 'Dune', ['author_name' => ['Frank Herbert']]),
        ])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('g1', ['title' => 'Dune', 'authors' => ['Brian Herbert']]),
        ])),
    ]);

    expect(searchService()->search('dune'))->toHaveCount(2);
});

it('de-duplicates within a single provider too', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([
            olDoc('/works/OL1W', 'Dune'),
            olDoc('/works/OL2W', 'DUNE'),
            olDoc('/works/OL3W', '  dune  '),
        ])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    $results = searchService()->search('dune');

    expect($results)->toHaveCount(1)
        ->and($results->first()->openLibraryId)->toBe('/works/OL1W');
});

it('collapses titles that differ only in punctuation or spacing', function (string $a, string $b) {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL1W', $a)])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([gVolume('g1', ['title' => $b])])),
    ]);

    expect(searchService()->search('x'))->toHaveCount(1);
})->with([
    ['The Hobbit', 'The Hobbit!'],
    ['Slaughterhouse-Five', 'Slaughterhouse Five'],
    ['Cat\'s Cradle', 'Cats Cradle'],
    ['2001: A Space Odyssey', '2001 A Space Odyssey'],
]);

it('collapses every non-Latin title into one entry, which is a real flaw', function () {
    // normalise() strips everything outside [a-z0-9], so two unrelated books with
    // Cyrillic or CJK titles both key on the empty string and one of them is thrown
    // away. The .NET regex [^a-z0-9] has exactly the same hole, and the original
    // JavaScript before it. Pinned here rather than fixed, so the port stays honest
    // and the flaw is visible; MIGRATION.md says what fixing it would take.
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([
            olDoc('/works/OL1W', 'Война и мир'),
            olDoc('/works/OL2W', '雪国'),
        ])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    expect(searchService()->search('x'))->toHaveCount(1);
});

// --- Mapping ---------------------------------------------------------------

it('maps the Open Library fields and builds the cover url', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([
            olDoc('/works/OL1W', 'Dune', [
                'subtitle' => 'A sci-fi classic',
                'author_name' => ['Frank Herbert', 'Someone Else'],
                'first_publish_year' => 1965,
                'number_of_pages_median' => 412,
                'cover_i' => 12345,
            ]),
        ])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    $book = searchService()->search('dune')->sole();

    expect($book->openLibraryId)->toBe('/works/OL1W')
        ->and($book->title)->toBe('Dune')
        ->and($book->subtitle)->toBe('A sci-fi classic')
        ->and($book->author)->toBe('Frank Herbert') // first author only
        ->and($book->firstPublishYear)->toBe(1965)
        ->and($book->pageCount)->toBe(412)
        ->and($book->coverUrl)->toBe('https://covers.openlibrary.org/b/id/12345-M.jpg');
});

it('tolerates an Open Library document with only the required fields', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL9W', 'Sparse')])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    $book = searchService()->search('sparse')->sole();

    expect($book->title)->toBe('Sparse')
        ->and($book->author)->toBeNull()
        ->and($book->subtitle)->toBeNull()
        ->and($book->coverUrl)->toBeNull()
        ->and($book->pageCount)->toBeNull()
        ->and($book->firstPublishYear)->toBeNull();
});

it('maps the Google fields, namespaces the id and upgrades the cover to https', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('abc', [
                'title' => 'Dune Messiah',
                'authors' => ['Frank Herbert'],
                'publishedDate' => '1969-10-15',
                'pageCount' => 256,
                'imageLinks' => ['thumbnail' => 'http://books.google.com/cover.jpg'],
            ]),
        ])),
    ]);

    $book = searchService()->search('dune messiah')->sole();

    expect($book->openLibraryId)->toBe('google:abc')
        ->and($book->title)->toBe('Dune Messiah')
        ->and($book->author)->toBe('Frank Herbert')
        ->and($book->firstPublishYear)->toBe(1969)
        ->and($book->pageCount)->toBe(256)
        ->and($book->coverUrl)->toBe('https://books.google.com/cover.jpg');
});

it('builds a series subtitle, combining it with an existing subtitle', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('1', ['title' => 'A', 'subtitle' => 'Origins', 'seriesInfo' => ['bookDisplayNumber' => '2']]),
            gVolume('2', ['title' => 'B', 'seriesInfo' => ['bookDisplayNumber' => '3']]),
        ])),
    ]);

    $results = searchService()->search('series');

    expect($results[0]->subtitle)->toBe('Book 2 · Origins')
        ->and($results[1]->subtitle)->toBe('Book 3');
});

it('parses the Google publish year the way parseInt does', function (?string $publishedDate, ?int $expected) {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([
            gVolume('y', ['title' => 'T', 'publishedDate' => $publishedDate]),
        ])),
    ]);

    expect(searchService()->search('t')->sole()->firstPublishYear)->toBe($expected);
})->with([
    ['1969-10-15', 1969],
    ['1965', 1965],
    ['198?', 198],       // MARC-style fuzzy date
    ['19uu', 19],        // MARC-style fuzzy date
    ['0000', null],      // parseInt's `0 || null`
    ['unknown', null],
    [null, null],
]);

// --- Not leaking the credential -------------------------------------------

it('keeps the Google API key out of the log when a provider fails', function () {
    // Guzzle puts the full request URL into a connection-failure message, and the
    // key travels in the query string because that is the only way Google Books
    // takes it. Without redaction, a DNS blip writes the credential into
    // storage/logs. Found by triggering a connection failure on purpose and reading
    // what came out, not by review.
    config()->set('services.google_books.api_key', 'SUPER-SECRET-KEY');

    Log::spy();

    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => fn () => throw new ConnectionException(
            'cURL error 6: Could not resolve host for '
            .'https://www.googleapis.com/books/v1/volumes?q=dune&maxResults=15&key=SUPER-SECRET-KEY'
        ),
    ]);

    searchService()->search('dune');

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        return str_contains($message, 'Google Books search failed')
            && ! str_contains($context['reason'], 'SUPER-SECRET-KEY')
            && str_contains($context['reason'], 'key=REDACTED');
    });
});

it('still says enough in the log to diagnose the failure', function () {
    Log::spy();

    Http::fake([
        OPEN_LIBRARY_URL => Http::response('gateway timeout', 504),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    searchService()->search('dune');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'Open Library search failed')
            && str_contains($context['reason'], '504')
    );
});

// --- Malformed provider shapes ---------------------------------------------

it('skips a malformed Google item instead of dropping the whole Google response', function () {
    // Review finding: a single non-array element used to raise a TypeError inside
    // the map, which settle() caught at the whole-response level, discarding every
    // good hit alongside the bad one.
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => Http::response(['items' => [
            gVolume('g1', ['title' => 'Good One']),
            null,
            'not an object',
            gVolume('g2', ['title' => 'Good Two']),
        ]]),
    ]);

    expect(searchService()->search('x')->pluck('title')->all())->toBe(['Good One', 'Good Two']);
});

it('skips a malformed Open Library doc the same way', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(['docs' => [olDoc('/works/OL1W', 'Good'), 42, olDoc('/works/OL2W', 'Also Good')]]),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    expect(searchService()->search('x'))->toHaveCount(2);
});

it('takes a bare-string author_name from Open Library whole, not its first character', function () {
    // PHP's string offset access would silently turn "J.R.R. Tolkien"[0] into "J".
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([olDoc('/works/OL1W', 'The Hobbit', ['author_name' => 'J.R.R. Tolkien'])])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([])),
    ]);

    expect(searchService()->search('hobbit')->sole()->author)->toBe('J.R.R. Tolkien');
});

it('takes a bare-string authors field from Google the same way', function () {
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([gVolume('g1', ['title' => 'Dune', 'authors' => 'Frank Herbert'])])),
    ]);

    expect(searchService()->search('dune')->sole()->author)->toBe('Frank Herbert');
});

it('upgrades only the leading scheme of a cover url to https', function () {
    // The .NET port's Replace("http:", "https:") rewrote every occurrence, which
    // would corrupt an embedded URL in a query string. The JavaScript original's
    // String.replace touched the first occurrence only. This follows the original.
    Http::fake([
        OPEN_LIBRARY_URL => Http::response(openLibraryDocs([])),
        GOOGLE_BOOKS_URL => Http::response(googleVolumes([gVolume('g1', [
            'title' => 'T',
            'imageLinks' => ['thumbnail' => 'http://books.google.com/c.jpg?next=http://example.com/x'],
        ])])),
    ]);

    expect(searchService()->search('t')->sole()->coverUrl)
        ->toBe('https://books.google.com/c.jpg?next=http://example.com/x');
});
