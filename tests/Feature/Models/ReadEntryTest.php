<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('casts format to the backed enum and stores its readable name', function () {
    $entry = ReadEntry::factory()->create(['format' => Format::Audiobook]);

    expect($entry->fresh()->format)->toBe(Format::Audiobook)
        ->and(DB::table('read_entries')->value('format'))->toBe('Audiobook');
});

it('stores finished_at as a date with no time component', function () {
    $entry = ReadEntry::factory()->create(['finished_at' => '2024-03-03']);

    expect($entry->fresh()->finished_at->toDateString())->toBe('2024-03-03')
        ->and(DB::table('read_entries')->value('finished_at'))->toStartWith('2024-03-03');
});

it('treats a null rating and a zero rating as different values', function () {
    $unrated = ReadEntry::factory()->create(['rating' => null]);
    $zero = ReadEntry::factory()->create(['rating' => 0]);

    expect($unrated->fresh()->rating)->toBeNull()
        ->and($zero->fresh()->rating)->toBe(0);
});

it('belongs to a user and a book', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create();

    $entry = ReadEntry::factory()->for($user)->for($book)->create();

    expect($entry->user->is($user))->toBeTrue()
        ->and($entry->book->is($book))->toBeTrue();
});
