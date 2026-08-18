<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

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

it('rejects an edit that would collide with another entry on the same date', function () {
    // The unique (user, book, finished date) index applies to edits too. The source
    // has no explicit handling for this either, so the check is that it surfaces as
    // a server error rather than silently corrupting anything.
    $user = User::factory()->create();
    $book = Book::factory()->create();
    ReadEntry::factory()->for($user)->for($book)->create(['finished_at' => '2024-01-01']);
    $second = ReadEntry::factory()->for($user)->for($book)->create(['finished_at' => '2024-02-01']);

    // The request runs inside a savepoint. In production every request is its own
    // transaction scope, so a failed UPDATE leaves the connection usable. Under
    // RefreshDatabase the whole test is one transaction, and on Postgres a failed
    // statement aborts that transaction, so the ->fresh() below would fail with
    // "current transaction is aborted" instead of proving the row is untouched.
    // Rolling back to a savepoint reproduces the production boundary.
    expect(fn () => DB::transaction(fn () => actingAsReader($user)
        ->withoutExceptionHandling()
        ->put("/library/{$second->id}", [
            'format' => Format::Book->value,
            'finished_at' => '2024-01-01',
            'rating' => null,
        ])))->toThrow(UniqueConstraintViolationException::class);

    expect($second->fresh()->finished_at->toDateString())->toBe('2024-02-01');
});
