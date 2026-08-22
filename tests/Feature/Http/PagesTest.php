<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Support\Str;

/*
| The feed, the account page, the book page, the guarded routes and the demo
| reader switch.
*/

// --- Feed ------------------------------------------------------------------

it('renders the feed for an anonymous visitor', function () {
    $this->get('/')->assertOk()->assertSee('Recently Read');
});

it('invites the first entry when nothing has been logged', function () {
    $this->get('/')->assertSee('No books logged yet. Be the first!');
});

it('shows reads from every user on the feed', function () {
    foreach (['Alice Book', 'Bob Book'] as $title) {
        ReadEntry::factory()
            ->for(User::factory()->sharesPublicly())
            ->for(Book::factory()->create(['title' => $title]))
            ->create();
    }

    $this->get('/')->assertSee('Alice Book')->assertSee('Bob Book');
});

it('never names the reader in the feed itself', function () {
    // The guarantee is about the feed projection: PublicRead carries no user fields,
    // so nothing in <main> can name whoever logged the book. A signed-in reader's
    // own name does appear elsewhere on the page, in the account menu in the
    // navigation bar, which is not part of the feed. Scoping the assertion to
    // <main> is what makes this test about the thing it claims to be about.
    $user = User::factory()->sharesPublicly()->create(['name' => 'Very Private Person']);
    ReadEntry::factory()->for($user)->create();

    $html = $this->get('/')->getContent();
    $main = Str::between($html, '<main', '</main>');

    expect($main)->not->toContain('Very Private Person')
        ->and($main)->toContain('Recently Read');
});

it('links a feed card through to the book page', function () {
    $book = Book::factory()->create(['title' => 'Dune', 'author' => 'Frank Herbert']);
    ReadEntry::factory()->for(User::factory()->sharesPublicly())->for($book)->create();

    // route() percent-encodes the space rather than using +, which is why this
    // asserts %20. Both are legal in a query string; the point is that the link is
    // built by the router and not by string concatenation.
    $this->get('/')->assertSee('/book?title=Dune&amp;author=Frank%20Herbert', false);
});

// --- Account ---------------------------------------------------------------

it('shows the reader name and the logged count', function () {
    $user = User::factory()->create(['name' => 'Count Reader']);
    ReadEntry::factory()->for($user)->create(['format' => Format::Book]);

    actingAsReader($user)->get('/account')
        ->assertOk()
        ->assertSee('Count Reader')
        ->assertSee('Reading stats')
        ->assertSee('book logged')   // singular for exactly one
        ->assertSee('1 Books');      // the per-format chip
});

it('pluralises the logged count', function () {
    $user = User::factory()->create();
    ReadEntry::factory()->count(2)->for($user)->create();

    actingAsReader($user)->get('/account')->assertSee('books logged');
});

it('omits format chips with a zero count', function () {
    $user = User::factory()->create();
    ReadEntry::factory()->for($user)->create(['format' => Format::Book]);

    actingAsReader($user)->get('/account')
        ->assertSee('1 Books')
        ->assertDontSee('Audiobooks')
        ->assertDontSee('E-books');
});

it('falls back to an initial when there is no avatar', function () {
    $user = User::factory()->create(['name' => 'zoe lowercase', 'image' => null]);

    actingAsReader($user)->get('/account')->assertSee('>Z<', false);
});

it('shows the avatar image when the reader has one', function () {
    $user = User::factory()->create(['image' => 'https://example.test/avatar.png']);

    actingAsReader($user)->get('/account')->assertSee('https://example.test/avatar.png');
});

it('counts only the acting reader\'s books in the stats', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    ReadEntry::factory()->for($mine)->create();
    ReadEntry::factory()->count(5)->for($theirs)->create();

    actingAsReader($mine)->get('/account')->assertSee('book logged')->assertSee('1 Books');
});

// --- Book detail -----------------------------------------------------------

it('renders the book page without any provider details', function () {
    // Same state readlog-dotnet is in with no Google Books API key configured.
    $this->get('/book?title=Dune&author=Frank+Herbert')
        ->assertOk()
        ->assertSee('Dune')
        ->assertSee('No details available for this book.');
});

it('returns 404 for a book page with no title', function () {
    $this->get('/book')->assertNotFound();
    $this->get('/book?title=')->assertNotFound();
});

// --- Who may read, and who may write ----------------------------------------

it('sends a signed-out visitor to sign in before letting them write', function (string $path) {
    // .NET counterpart: [Authorize] redirecting an anonymous visitor to /signin,
    // which is now what this does too. Reading is deliberately not on this list:
    // the public URL is a portfolio link, and a login wall would waste it.
    $this->get($path)->assertRedirect('/signin');
})->with(['/log', '/account']);

it('lets anyone read the feed, a book page and the showcase library', function () {
    $showcase = User::factory()->sharesPublicly()->create(['name' => 'Showcase']);
    ReadEntry::factory()->for($showcase)->for(Book::factory()->create(['title' => 'Shown Book']))->create();

    $this->get('/')->assertOk();
    $this->get('/book?title=Dune')->assertOk();
    $this->get('/library')->assertOk()->assertSee('Shown Book');
});

it('shows a signed-out visitor the showcase library, not whoever registered first', function () {
    // The old rule was "the oldest account", which was safe when the only accounts
    // were seeded. Anyone can sign in now, so the oldest account can be a stranger.
    $stranger = User::factory()->create(['name' => 'Stranger']);
    ReadEntry::factory()->for($stranger)->for(Book::factory()->create(['title' => 'Private Book']))->create();

    $showcase = User::factory()->sharesPublicly()->create(['name' => 'Showcase']);
    ReadEntry::factory()->for($showcase)->for(Book::factory()->create(['title' => 'Public Book']))->create();

    $this->get('/library')->assertOk()->assertSee('Public Book')->assertDontSee('Private Book');
});

it('lets a signed-in reader through every route', function (string $path) {
    $user = User::factory()->create();

    actingAsReader($user)->get($path)->assertOk();
})->with(['/library', '/log', '/account']);

it('shows the sign-in link to a visitor and the name and sign-out to a reader', function () {
    $this->get('/')->assertOk()->assertSee('Sign in')->assertDontSee('Sign out');

    actingAsReader(User::factory()->create(['name' => 'Signed In Reader']))
        ->get('/')
        ->assertOk()
        ->assertSee('Signed In Reader')
        ->assertSee('Sign out');
});
