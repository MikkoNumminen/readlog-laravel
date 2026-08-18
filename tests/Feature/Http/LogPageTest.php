<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;

/*
| Port of the logging cases in tests/ReadLog.Tests/Pages/UiPagesTests.cs.
|
| One structural difference runs through this file. The .NET page re-renders
| itself with a 200 when a post fails, because Razor Pages returns Page(). Laravel
| redirects back with the errors in the session and the browser re-requests the
| form, so the assertions are assertRedirect plus assertSessionHasErrors rather
| than a status code and a string match. Same behaviour for the user, different
| shape in the test.
*/

function todayString(): string
{
    return now()->toDateString();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function logForm(array $overrides = []): array
{
    return array_merge([
        'open_library_id' => 'manual:t1',
        'title' => 'The Test Book',
        'author' => null,
        'cover_url' => null,
        'page_count' => null,
        'first_publish_year' => null,
        'format' => Format::Book->value,
        'finished_at' => todayString(),
        'rating' => null,
    ], $overrides);
}

it('shows the search form before a book is chosen', function () {
    $user = User::factory()->create();

    actingAsReader($user)->get('/log')
        ->assertOk()
        ->assertSee('Book title')
        ->assertDontSee('Save to library');
});

it('offers a manual add after a search that finds nothing', function () {
    $user = User::factory()->create();

    // Provider search arrives in phase 3, so every search currently falls through
    // to the manual-add path, which is the state the .NET app is in when neither
    // provider returns a hit.
    actingAsReader($user)->get('/log?title=Some+Obscure+Title')
        ->assertOk()
        ->assertSee('No books found.')
        ->assertSee('Add &quot;Some Obscure Title&quot; manually', false);
});

it('prefills the log form once a book is chosen', function () {
    $user = User::factory()->create();

    actingAsReader($user)
        ->get('/log?olid=manual:t1&sel_title=The+Test+Book&sel_author=A+Writer&pages=300&year=1999')
        ->assertOk()
        ->assertSee('Save to library')
        ->assertSee('value="The Test Book"', false)
        ->assertSee('value="A Writer"', false)
        ->assertSee('value="300"', false)
        ->assertSee('value="1999"', false);
});

it('logs a book and shows it in the library', function () {
    $user = User::factory()->create();

    actingAsReader($user)
        ->post('/log', logForm(['format' => Format::Audiobook->value, 'rating' => '4']))
        ->assertRedirect('/library');

    $entry = ReadEntry::with('book')->sole();

    expect($entry->book->title)->toBe('The Test Book')
        ->and($entry->format)->toBe(Format::Audiobook)
        ->and($entry->rating)->toBe(4);

    actingAsReader($user)->get('/library')->assertSee('The Test Book');
});

it('reports a conflict when the same book is logged twice on the same date', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm())->assertRedirect('/library');

    actingAsReader($user)->post('/log', logForm())
        ->assertRedirect()
        ->assertSessionHasErrors(['form' => "You've already logged this book with that finished date."]);

    expect(ReadEntry::count())->toBe(1);
});

it('rejects a finished date in the future', function () {
    $user = User::factory()->create();

    actingAsReader($user)
        ->post('/log', logForm(['finished_at' => now()->addDay()->toDateString()]))
        ->assertSessionHasErrors('finished_at');

    expect(ReadEntry::count())->toBe(0);
});

it('accepts a finished date of today', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm(['finished_at' => todayString()]))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/library');
});

it('rejects a rating above five', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm(['rating' => 6]))
        ->assertSessionHasErrors('rating');
});

it('accepts a rating of zero, which is not the same as no rating', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm(['rating' => 0]))->assertSessionHasNoErrors();

    expect(ReadEntry::sole()->rating)->toBe(0);
});

it('requires a title', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm(['title' => '']))->assertSessionHasErrors('title');
});

it('requires a provider id', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm(['open_library_id' => '']))
        ->assertSessionHasErrors('open_library_id');
});

it('rejects a format that is not one of the three', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm(['format' => 'Hardback']))
        ->assertSessionHasErrors('format');
});

it('rejects a cover url that is not a url', function () {
    $user = User::factory()->create();

    actingAsReader($user)->post('/log', logForm(['cover_url' => 'not a url']))
        ->assertSessionHasErrors('cover_url');
});

it('reuses the catalogue book when two readers log the same provider id', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    actingAsReader($first)->post('/log', logForm(['open_library_id' => '/works/OL1W', 'title' => 'Dune']));
    actingAsReader($second)->post('/log', logForm(['open_library_id' => '/works/OL1W', 'title' => 'Dune retitled']));

    expect(Book::count())->toBe(1)
        ->and(Book::sole()->title)->toBe('Dune') // first logger's metadata wins
        ->and(ReadEntry::count())->toBe(2);
});
