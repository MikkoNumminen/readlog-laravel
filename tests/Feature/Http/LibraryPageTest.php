<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;

it('says so when the library is empty', function () {
    $user = User::factory()->create();

    actingAsReader($user)->get('/library')
        ->assertOk()
        ->assertSee('No books logged yet.');
});

it('renders the grid view by default and the list view on request', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create(['title' => 'Listed Book']);
    ReadEntry::factory()->for($user)->for($book)->create();

    actingAsReader($user)->get('/library')->assertOk()->assertSee('Listed Book');

    actingAsReader($user)->get('/library?view=list')
        ->assertOk()
        ->assertSee('Listed Book')
        ->assertSee('Edit'); // the list view's per-row edit link
});

it('falls back to the grid for an unknown view parameter', function () {
    $user = User::factory()->create();
    ReadEntry::factory()->for($user)->create();

    actingAsReader($user)->get('/library?view=carousel')
        ->assertOk()
        ->assertSee('rl-grid', false);
});

it('reports matches and misses for the have-I-read-this lookup', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create(['title' => 'Searchable Saga']);
    ReadEntry::factory()->for($user)->for($book)->create();

    actingAsReader($user)->get('/library?q=saga')
        ->assertOk()
        ->assertSee('Yes! Found 1 match')
        ->assertSee('Searchable Saga');

    actingAsReader($user)->get('/library?q=nothinghere')
        ->assertOk()
        ->assertSee('Not in your library.');
});

it('pluralises the match count', function () {
    $user = User::factory()->create();
    foreach (['Dune', 'Dune Messiah'] as $title) {
        $book = Book::factory()->create(['title' => $title]);
        ReadEntry::factory()->for($user)->for($book)->create();
    }

    actingAsReader($user)->get('/library?q=dune')->assertSee('Yes! Found 2 matches');
});

it('shows no result panel at all when there is no q parameter', function () {
    $user = User::factory()->create();
    ReadEntry::factory()->for($user)->create();

    actingAsReader($user)->get('/library')
        ->assertDontSee('Not in your library.')
        ->assertDontSee('Yes! Found');
});

it('shows no result panel for an empty submitted search box', function () {
    // .NET: `Searched = !string.IsNullOrWhiteSpace(q)`, so q= renders nothing either.
    $user = User::factory()->create();
    ReadEntry::factory()->for($user)->create();

    actingAsReader($user)->get('/library?q=')
        ->assertDontSee('Not in your library.')
        ->assertDontSee('Yes! Found');
});

it('never shows another reader\'s books', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    ReadEntry::factory()->for($theirs)->for(Book::factory()->create(['title' => 'Their Secret Book']))->create();

    actingAsReader($mine)->get('/library')
        ->assertOk()
        ->assertDontSee('Their Secret Book');
});

it('keeps the search term when switching between grid and list', function () {
    $user = User::factory()->create();
    ReadEntry::factory()->for($user)->create();

    actingAsReader($user)->get('/library?q=dune')
        ->assertSee('view=list&amp;q=dune', false);
});

it('shows the format badge and the star rating', function () {
    $user = User::factory()->create();
    ReadEntry::factory()->for($user)->create(['format' => Format::Audiobook, 'rating' => 3]);

    actingAsReader($user)->get('/library?view=list')
        ->assertSee('Audiobook')
        ->assertSee('3 out of 5 stars');
});

it('renders no stars at all when a rating is null', function () {
    $user = User::factory()->create();
    ReadEntry::factory()->for($user)->create(['rating' => null]);

    actingAsReader($user)->get('/library?view=list')->assertDontSee('out of 5 stars');
});
