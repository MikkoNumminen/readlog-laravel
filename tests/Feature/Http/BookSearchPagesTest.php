<?php

use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
| The two pages that reach a provider: the log page's search, and the book detail
| page. Everything outbound is faked.
*/

beforeEach(function () {
    config()->set('services.google_books.api_key', 'test-key');
});

// --- Log page search -------------------------------------------------------

it('lists merged provider results on the log page', function () {
    $user = User::factory()->create();

    Http::fake([
        'openlibrary.org/*' => Http::response(['docs' => [[
            'key' => '/works/OL893414W',
            'title' => 'Dune',
            'author_name' => ['Frank Herbert'],
            'first_publish_year' => 1965,
            'cover_i' => 11481354,
        ]]]),
        'www.googleapis.com/*' => Http::response(['items' => [[
            'id' => 'g-messiah',
            'volumeInfo' => ['title' => 'Dune Messiah', 'authors' => ['Frank Herbert'], 'publishedDate' => '1969'],
        ]]]),
    ]);

    actingAsReader($user)->get('/log?title=dune')
        ->assertOk()
        ->assertSee('Dune')
        ->assertSee('Dune Messiah')
        ->assertSee('Frank Herbert')
        ->assertSee('1965')
        ->assertSeeInOrder(['Dune', 'Dune Messiah']); // Open Library first
});

it('carries a chosen result through to a prefilled log form', function () {
    $user = User::factory()->create();

    Http::fake([
        'openlibrary.org/*' => Http::response(['docs' => [[
            'key' => '/works/OL893414W',
            'title' => 'Dune',
            'author_name' => ['Frank Herbert'],
            'first_publish_year' => 1965,
            'number_of_pages_median' => 412,
            'cover_i' => 11481354,
        ]]]),
        'www.googleapis.com/*' => Http::response(['items' => []]),
    ]);

    $html = actingAsReader($user)->get('/log?title=dune')->getContent();

    expect($html)->toContain('olid=%2Fworks%2FOL893414W')
        ->and($html)->toContain('pages=412')
        ->and($html)->toContain('year=1965');

    actingAsReader($user)
        ->get('/log?olid=/works/OL893414W&sel_title=Dune&sel_author=Frank+Herbert&pages=412&year=1965')
        ->assertOk()
        ->assertSee('Save to library')
        ->assertSee('value="412"', false);
});

it('still offers the manual fallback when the providers do return hits', function () {
    // The source is explicit about this: the providers return irrelevant but
    // non-zero hits for niche titles, so hiding the manual option behind an empty
    // result list would strand them.
    $user = User::factory()->create();

    Http::fake([
        'openlibrary.org/*' => Http::response(['docs' => [['key' => '/works/OL1W', 'title' => 'Something Else']]]),
        'www.googleapis.com/*' => Http::response(['items' => []]),
    ]);

    actingAsReader($user)->get('/log?title=A+Niche+Audible+Original')
        ->assertOk()
        ->assertSee('Something Else')
        ->assertSee('Not the book you are looking for?')
        ->assertSee('manually');
});

it('degrades to an empty result list when both providers are down', function () {
    $user = User::factory()->create();

    Http::fake([
        'openlibrary.org/*' => Http::response('down', 503),
        'www.googleapis.com/*' => Http::response('down', 503),
    ]);

    actingAsReader($user)->get('/log?title=dune')
        ->assertOk() // the page still renders
        ->assertSee('No books found.')
        ->assertSee('manually');
});

it('logs a book found through search, using the provider id as the catalogue key', function () {
    $user = User::factory()->create();
    Http::fake();

    actingAsReader($user)->post('/log', [
        'open_library_id' => '/works/OL893414W',
        'title' => 'Dune',
        'author' => 'Frank Herbert',
        'cover_url' => 'https://covers.openlibrary.org/b/id/11481354-M.jpg',
        'page_count' => 412,
        'first_publish_year' => 1965,
        'format' => 'Book',
        'finished_at' => now()->toDateString(),
        'rating' => 5,
    ])->assertRedirect('/library');

    $book = Book::sole();

    expect($book->open_library_id)->toBe('/works/OL893414W')
        ->and($book->page_count)->toBe(412)
        ->and($book->first_publish_year)->toBe(1965);
});

// --- Book detail page ------------------------------------------------------

it('renders provider details on the book page', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [[
        'id' => 'x',
        'volumeInfo' => [
            'title' => 'Dune',
            'authors' => ['Frank Herbert'],
            'description' => '<p>A desert planet.</p>',
            'categories' => ['Fiction', 'Science Fiction'],
            'publisher' => 'Ace',
            'publishedDate' => '1965',
            'pageCount' => 412,
            'language' => 'en',
            'imageLinks' => ['thumbnail' => 'http://books.google.com/dune.jpg'],
            'infoLink' => 'https://books.google.com/dune',
        ],
    ]]])]);

    $this->get('/book?title=Dune&author=Frank+Herbert')
        ->assertOk()
        ->assertSee('Frank Herbert')
        ->assertSee('A desert planet.')
        ->assertSee('Science Fiction')
        ->assertSee('Ace')
        ->assertSee('412 pages')
        ->assertSee('Language: EN')
        ->assertSee('https://books.google.com/dune.jpg') // upgraded to https
        ->assertSee('More on Google Books', false);
});

it('sanitises the description before rendering it', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [[
        'id' => 'x',
        'volumeInfo' => [
            'title' => 'Nasty',
            'description' => '<p>Safe text</p><script>alert(1)</script><a href="javascript:alert(2)" target="_blank">x</a>',
        ],
    ]]])]);

    $html = $this->get('/book?title=Nasty')->assertOk()->getContent();

    expect($html)->toContain('Safe text')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and(strtolower($html))->not->toContain('javascript:alert');
});

it('refuses to render an info link that is not http or https', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [[
        'id' => 'x',
        'volumeInfo' => ['title' => 'Hostile', 'infoLink' => 'javascript:alert(1)'],
    ]]])]);

    $this->get('/book?title=Hostile')
        ->assertOk()
        ->assertDontSee('More on Google Books', false);
});

it('shows no details when there is no API key', function () {
    config()->set('services.google_books.api_key', null);
    Http::fake();

    $this->get('/book?title=Dune&author=Frank+Herbert')
        ->assertOk()
        ->assertSee('Dune')
        ->assertSee('No details available for this book.');

    Http::assertNothingSent();
});

it('falls back to the cover from the query string when the provider has none', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [[
        'id' => 'x',
        'volumeInfo' => ['title' => 'Dune'],
    ]]])]);

    $this->get('/book?title=Dune&cover=https%3A%2F%2Fcovers.openlibrary.org%2Fb%2Fid%2F1-M.jpg')
        ->assertOk()
        ->assertSee('https://covers.openlibrary.org/b/id/1-M.jpg');
});

it('reaches the book page from a feed card', function () {
    $book = Book::factory()->create(['title' => 'Dune', 'author' => 'Frank Herbert']);
    ReadEntry::factory()->for(User::factory()->sharesPublicly())->for($book)->create();
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);

    $this->get('/')->assertOk()->assertSee('/book?title=Dune', false);
    $this->get('/book?title=Dune&author=Frank%20Herbert')->assertOk()->assertSee('Dune');
});
