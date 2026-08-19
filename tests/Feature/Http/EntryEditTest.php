<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;

it('renders the edit form for an owned entry', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create(['title' => 'Original Title']);
    $entry = ReadEntry::factory()->for($user)->for($book)->create([
        'format' => Format::Book,
        'rating' => 3,
    ]);

    actingAsReader($user)->get("/library/{$entry->id}/edit")
        ->assertOk()
        ->assertSee('Edit entry')
        ->assertSee('Original Title')
        ->assertSee('shared catalogue');
});

it('updates the per-user fields but not the shared title', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create(['title' => 'Original Title']);
    $entry = ReadEntry::factory()->for($user)->for($book)->create(['rating' => 3]);

    actingAsReader($user)->put("/library/{$entry->id}", [
        'format' => Format::Ebook->value,
        'finished_at' => now()->toDateString(),
        'rating' => 5,
    ])->assertRedirect('/library');

    $updated = $entry->fresh()->load('book');

    expect($updated->format)->toBe(Format::Ebook)
        ->and($updated->rating)->toBe(5)
        ->and($updated->book->title)->toBe('Original Title');
});

it('clears a rating when the empty option is submitted', function () {
    $user = User::factory()->create();
    $entry = ReadEntry::factory()->for($user)->create(['rating' => 4]);

    actingAsReader($user)->put("/library/{$entry->id}", [
        'format' => Format::Book->value,
        'finished_at' => now()->toDateString(),
        'rating' => '',
    ])->assertRedirect('/library');

    expect($entry->fresh()->rating)->toBeNull();
});

it('deletes an entry and keeps the catalogue book', function () {
    $user = User::factory()->create();
    $entry = ReadEntry::factory()->for($user)->create();

    actingAsReader($user)->delete("/library/{$entry->id}")->assertRedirect('/library');

    expect(ReadEntry::count())->toBe(0)
        ->and(Book::count())->toBe(1);
});

it('returns 404 for an entry that does not exist', function () {
    $user = User::factory()->create();

    actingAsReader($user)->get('/library/99999/edit')->assertNotFound();
});

it('returns 404, not 403, for another reader\'s entry', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $entry = ReadEntry::factory()->for($owner)->create(['rating' => 3]);

    actingAsReader($stranger)->get("/library/{$entry->id}/edit")->assertNotFound();

    actingAsReader($stranger)->put("/library/{$entry->id}", [
        'format' => Format::Ebook->value,
        'finished_at' => now()->toDateString(),
        'rating' => 0,
    ])->assertNotFound();

    actingAsReader($stranger)->delete("/library/{$entry->id}")->assertNotFound();

    expect($entry->fresh()->rating)->toBe(3); // untouched throughout
});

it('rejects a future finished date on edit', function () {
    $user = User::factory()->create();
    $entry = ReadEntry::factory()->for($user)->create(['finished_at' => '2024-01-01']);

    actingAsReader($user)->put("/library/{$entry->id}", [
        'format' => Format::Book->value,
        'finished_at' => now()->addDay()->toDateString(),
        'rating' => null,
    ])->assertSessionHasErrors('finished_at');

    expect($entry->fresh()->finished_at->toDateString())->toBe('2024-01-01');
});

it('rejects an out-of-range rating on edit', function () {
    $user = User::factory()->create();
    $entry = ReadEntry::factory()->for($user)->create(['rating' => 2]);

    actingAsReader($user)->put("/library/{$entry->id}", [
        'format' => Format::Book->value,
        'finished_at' => now()->toDateString(),
        'rating' => 9,
    ])->assertSessionHasErrors('rating');

    expect($entry->fresh()->rating)->toBe(2);
});

it('rejects an edit that would collide with another entry on the same date, with a message', function () {
    // The unique (user, book, finished date) index applies to edits too. The
    // source has no handling for this and answers 500; that hole was ported as
    // found (see DECISIONS.md 27) and is now closed: the same domain exception
    // logBook raises, the same message the log form shows, the row untouched.
    $user = User::factory()->create();
    $book = Book::factory()->create();
    ReadEntry::factory()->for($user)->for($book)->create(['finished_at' => '2024-01-01']);
    $second = ReadEntry::factory()->for($user)->for($book)->create(['finished_at' => '2024-02-01']);

    actingAsReader($user)->from("/library/{$second->id}/edit")->put("/library/{$second->id}", [
        'format' => Format::Book->value,
        'finished_at' => '2024-01-01',
        'rating' => null,
    ])
        ->assertRedirect("/library/{$second->id}/edit")
        ->assertSessionHasErrors(['form' => "You've already logged this book with that finished date."]);

    expect($second->fresh()->finished_at->toDateString())->toBe('2024-02-01');
});

it('still lets an entry keep its own date on edit', function () {
    // Saving the form without changing the date must not be mistaken for a
    // collision with itself.
    $user = User::factory()->create();
    $entry = ReadEntry::factory()->for($user)->create(['finished_at' => '2024-02-01', 'rating' => 1]);

    actingAsReader($user)->put("/library/{$entry->id}", [
        'format' => Format::Book->value,
        'finished_at' => '2024-02-01',
        'rating' => 5,
    ])->assertRedirect('/library');

    expect($entry->fresh()->rating)->toBe(5);
});
