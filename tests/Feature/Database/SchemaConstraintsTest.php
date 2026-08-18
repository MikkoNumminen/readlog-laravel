<?php

use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The database-level guarantees carried over from ApplicationDbContext.OnModelCreating.
| These are schema tests, not model tests: they go through the query builder so the
| constraint is what fails, not an Eloquent guard in front of it.
*/

it('rejects a second book with the same open_library_id', function () {
    Book::create(['title' => 'Dune', 'open_library_id' => '/works/OL1W']);

    expect(fn () => Book::create(['title' => 'Dune (reissue)', 'open_library_id' => '/works/OL1W']))
        ->toThrow(QueryException::class);
});

it('allows many books with no open_library_id at all', function () {
    Book::create(['title' => 'One']);
    Book::create(['title' => 'Two']);

    // A unique index over a nullable column still permits repeated NULLs in SQLite,
    // the same as in the .NET schema.
    expect(Book::count())->toBe(2);
});

it('rejects a second entry for the same user, book and finished date', function () {
    $entry = ReadEntry::factory()->create(['finished_at' => '2024-01-01']);

    expect(fn () => ReadEntry::factory()->create([
        'user_id' => $entry->user_id,
        'book_id' => $entry->book_id,
        'finished_at' => '2024-01-01',
    ]))->toThrow(QueryException::class);
});

it('allows the same user to log the same book on a different date', function () {
    $entry = ReadEntry::factory()->create(['finished_at' => '2024-01-01']);

    ReadEntry::factory()->create([
        'user_id' => $entry->user_id,
        'book_id' => $entry->book_id,
        'finished_at' => '2025-01-01',
    ]);

    expect(ReadEntry::count())->toBe(2);
});

it('allows two users to log the same catalogue book on the same date', function () {
    $book = Book::factory()->create();
    ReadEntry::factory()->for($book)->create(['finished_at' => '2024-01-01']);
    ReadEntry::factory()->for($book)->create(['finished_at' => '2024-01-01']);

    expect(Book::count())->toBe(1)
        ->and(ReadEntry::count())->toBe(2);
});

it('deletes a user\'s entries with the user', function () {
    $entry = ReadEntry::factory()->create();

    $entry->user->delete();

    expect(ReadEntry::count())->toBe(0)
        ->and(Book::count())->toBe(1); // the shared catalogue row survives
});

it('refuses to delete a book that entries still point at', function () {
    $entry = ReadEntry::factory()->create();

    expect(fn () => $entry->book->delete())->toThrow(QueryException::class);
});

/*
| Known deviation, pinned so it is visible rather than forgotten.
|
| The .NET schema carries CK_ReadEntry_Rating: rating must be null or 0-5. Laravel's
| schema builder cannot express a check constraint and SQLite cannot add one to an
| existing table, so the bound lives in request validation instead. This test asserts
| the gap that exists today; if a future change adds a database-level constraint (a
| trigger, or a raw CREATE TABLE), this test is the one that should fail and be
| rewritten. See MIGRATION.md.
*/
it('does not enforce the 0-5 rating bound in the database, unlike the .NET schema', function () {
    $entry = ReadEntry::factory()->create();

    DB::table('read_entries')->where('id', $entry->id)->update(['rating' => 9]);

    expect($entry->fresh()->rating)->toBe(9);
});
