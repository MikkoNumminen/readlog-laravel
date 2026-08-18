<?php

use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
| .NET counterpart: tests/ReadLog.Tests/Data/ApplicationDbContextTests.cs, which
| checks the same mapping decisions from the other side of the ORM divide.
*/

it('stamps created_at on insert and has no updated_at column', function () {
    $book = Book::create(['title' => 'Dune']);

    expect($book->created_at)->not->toBeNull()
        ->and(Schema::hasColumn('books', 'updated_at'))->toBeFalse();
});

it('leaves created_at alone when the row is updated', function () {
    $book = Book::create(['title' => 'Dune']);
    $stamped = $book->created_at;

    $book->update(['author' => 'Frank Herbert']);

    expect($book->fresh()->created_at->equalTo($stamped))->toBeTrue();
});

it('casts the numeric columns back to integers', function () {
    Book::create(['title' => 'Dune', 'page_count' => 606, 'first_publish_year' => 1965]);

    $book = Book::sole();

    expect($book->page_count)->toBeInt()->toBe(606)
        ->and($book->first_publish_year)->toBeInt()->toBe(1965);
});

it('keeps every optional catalogue field nullable', function () {
    $book = Book::create(['title' => 'Sparse']);

    expect($book->author)->toBeNull()
        ->and($book->cover_url)->toBeNull()
        ->and($book->open_library_id)->toBeNull()
        ->and($book->page_count)->toBeNull()
        ->and($book->first_publish_year)->toBeNull();
});

it('exposes its read entries', function () {
    $book = Book::factory()->create();
    ReadEntry::factory()->count(2)->for($book)->for(User::factory())->create();

    expect($book->readEntries)->toHaveCount(2);
});
