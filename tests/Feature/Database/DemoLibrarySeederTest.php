<?php

use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Database\Seeders\DemoLibrarySeeder;

it('seeds a library that is worth looking at straight after migrating', function () {
    $this->seed(DemoLibrarySeeder::class);

    expect(User::count())->toBe(2)
        ->and(Book::count())->toBe(12)
        ->and(ReadEntry::count())->toBe(14);
});

it('gives every seeded book a real Open Library work key and cover', function () {
    $this->seed(DemoLibrarySeeder::class);

    Book::each(function (Book $book) {
        expect($book->open_library_id)->toStartWith('/works/OL')
            ->and($book->cover_url)->toStartWith('https://covers.openlibrary.org/b/id/');
    });
});

it('shares one catalogue row between two readers of the same book', function () {
    $this->seed(DemoLibrarySeeder::class);

    $dune = Book::where('title', 'Dune')->sole();

    expect($dune->readEntries)->toHaveCount(2)
        ->and($dune->readEntries->pluck('user_id')->unique())->toHaveCount(2);
});

it('never seeds a finished date in the future', function () {
    $this->seed(DemoLibrarySeeder::class);

    ReadEntry::each(fn (ReadEntry $entry) => expect($entry->finished_at->isFuture())->toBeFalse());
});

it('is idempotent, so seeding twice does not duplicate anything', function () {
    $this->seed(DemoLibrarySeeder::class);
    $this->seed(DemoLibrarySeeder::class);

    expect(User::count())->toBe(2)
        ->and(Book::count())->toBe(12)
        ->and(ReadEntry::count())->toBe(14);
});

it('runs correctly with mass-assignment guards on', function () {
    // `artisan db:seed` wraps seeders in Model::unguarded(), which hides any attribute
    // the allowlist would otherwise drop. Running the seeder directly keeps the guards
    // on, so a regression here would mean the seeder had started leaning on that.
    (new DemoLibrarySeeder)->run();

    expect(User::whereNotNull('email_verified_at')->count())->toBe(2)
        ->and(Book::count())->toBe(12)
        ->and(ReadEntry::count())->toBe(14);
});

it('anchors every date to a fixed day, so seeding on a later day is still a no-op', function () {
    // The first version counted back from today(). Run again tomorrow, every
    // finished_at shifts by one, the firstOrNew lookups miss, and the library
    // doubles. Wired to run on every deploy, that would have added fourteen entries
    // per day. Travelling forward before the second run is what proves the fix.
    $this->seed(DemoLibrarySeeder::class);

    $this->travel(30)->days();
    $this->seed(DemoLibrarySeeder::class);

    expect(ReadEntry::count())->toBe(14)
        ->and(ReadEntry::max('finished_at'))->toBe('2026-08-10');
});

it('seeds the demo library through DatabaseSeeder only into an empty catalogue', function () {
    // DatabaseSeeder is what runs on deploy. It must not resurrect demo rows the
    // author has removed from a live instance, so it seeds once and then stands aside.
    $this->seed();
    expect(Book::count())->toBe(12);

    Book::query()->whereIn('title', ['Dune', 'Piranesi'])->each(function (Book $book) {
        $book->readEntries()->delete();
        $book->delete();
    });

    $this->seed();

    expect(Book::count())->toBe(10)
        ->and(Book::where('title', 'Dune')->exists())->toBeFalse();
});

it('can still be forced into a populated catalogue by naming the seeder', function () {
    $this->seed();
    Book::query()->where('title', 'Dune')->each(function (Book $book) {
        $book->readEntries()->delete();
        $book->delete();
    });

    $this->seed(DemoLibrarySeeder::class);

    expect(Book::count())->toBe(12);
});
