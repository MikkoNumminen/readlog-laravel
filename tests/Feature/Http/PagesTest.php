<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use App\Services\CurrentUser;
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
            ->for(User::factory())
            ->for(Book::factory()->create(['title' => $title]))
            ->create();
    }

    $this->get('/')->assertSee('Alice Book')->assertSee('Bob Book');
});

it('never names the reader in the feed itself', function () {
    // The guarantee is about the feed projection: PublicRead carries no user fields,
    // so nothing in <main> can name whoever logged the book. The reader's name does
    // appear elsewhere on the page, in the demo switcher in the navigation bar,
    // which is a demo affordance and not part of the feed. Scoping the assertion to
    // <main> is what makes this test about the thing it claims to be about.
    $user = User::factory()->create(['name' => 'Very Private Person']);
    ReadEntry::factory()->for($user)->create();

    $html = $this->get('/')->getContent();
    $main = Str::between($html, '<main', '</main>');

    expect($main)->not->toContain('Very Private Person')
        ->and($main)->toContain('Recently Read');
});

it('links a feed card through to the book page', function () {
    $book = Book::factory()->create(['title' => 'Dune', 'author' => 'Frank Herbert']);
    ReadEntry::factory()->for(User::factory())->for($book)->create();

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

// --- Guarded routes --------------------------------------------------------

it('sends a visitor home when no reader exists at all', function (string $path) {
    // .NET counterpart: [Authorize] redirecting an anonymous visitor to /signin.
    // Version 1 has no sign-in page, so an unseeded database is the only way to be
    // "not a reader", and the middleware explains what to do about it.
    $this->get($path)
        ->assertRedirect('/')
        ->assertSessionHas('notice');
})->with(['/library', '/log', '/account']);

it('lets a reader through the guarded routes', function (string $path) {
    $user = User::factory()->create();

    actingAsReader($user)->get($path)->assertOk();
})->with(['/library', '/log', '/account']);

it('leaves the feed and the book page open to everyone', function () {
    $this->get('/')->assertOk();
    $this->get('/book?title=Dune')->assertOk();
});

// --- Demo reader switch ----------------------------------------------------

it('switches the acting reader', function () {
    $first = User::factory()->create(['name' => 'First Reader']);
    $second = User::factory()->create(['name' => 'Second Reader']);
    ReadEntry::factory()->for($second)->for(Book::factory()->create(['title' => 'Second Book']))->create();

    actingAsReader($first)->get('/library')->assertDontSee('Second Book');

    $this->from('/library')->post('/demo-user', ['user_id' => $second->id])
        ->assertRedirect('/library')
        ->assertSessionHas(CurrentUser::SESSION_KEY, $second->id);

    $this->get('/library')->assertSee('Second Book');
});

it('rejects a switch to a reader that does not exist', function () {
    User::factory()->create();

    $this->post('/demo-user', ['user_id' => 99999])->assertSessionHasErrors('user_id');
});

it('defaults to the first reader when the session says nothing', function () {
    $first = User::factory()->create(['name' => 'First Reader']);
    User::factory()->create(['name' => 'Second Reader']);
    ReadEntry::factory()->for($first)->for(Book::factory()->create(['title' => 'First Book']))->create();

    $this->get('/library')->assertOk()->assertSee('First Book');
});

it('recovers when the session points at a deleted reader', function () {
    $stale = User::factory()->create();
    $survivor = User::factory()->create(['name' => 'Survivor']);
    $staleId = $stale->id;
    $stale->delete();

    $this->withSession([CurrentUser::SESSION_KEY => $staleId])
        ->get('/account')
        ->assertOk()
        ->assertSee($survivor->email);
});
