<?php

use App\Enums\Format;
use App\Exceptions\DuplicateReadEntryException;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use App\Services\ReadLogService;
use App\Support\LogBookData;
use App\Support\UpdateReadEntryData;
use Illuminate\Support\Facades\DB;

/*
| Port of tests/ReadLog.Tests/Services/ReadLogServiceTests.cs, case for case.
|
| The .NET suite opens and closes a DbContext around each step so it can prove a
| value really reached the database rather than sitting in the change tracker.
| Eloquent has no change tracker to fool: a save is a statement. Where the .NET test
| reopens a context, these tests call ->fresh() or query again, which is the same
| intent expressed in the idiom that exists here.
*/

function service(): ReadLogService
{
    return app(ReadLogService::class);
}

function logData(
    string $openLibraryId,
    string $title,
    string $finishedAt,
    Format $format = Format::Book,
    ?int $rating = null,
    ?string $author = null,
): LogBookData {
    return new LogBookData(
        openLibraryId: $openLibraryId,
        title: $title,
        author: $author,
        coverUrl: null,
        pageCount: null,
        firstPublishYear: null,
        format: $format,
        finishedAt: $finishedAt,
        rating: $rating,
    );
}

it('creates the book and the entry when a book is logged', function () {
    $user = User::factory()->create();

    service()->logBook($user->id, logData('/works/OL1W', 'Dune', '2024-01-01', rating: 5));

    $book = Book::sole();
    $entry = ReadEntry::sole();

    expect($book->title)->toBe('Dune')
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->book_id)->toBe($book->id)
        ->and($entry->rating)->toBe(5);
});

it('reuses an existing catalogue book and keeps the first metadata', function () {
    $user = User::factory()->create();

    service()->logBook($user->id, logData('/works/OL1W', 'Dune', '2024-01-01'));
    service()->logBook($user->id, logData('/works/OL1W', 'A different title', '2024-02-02'));

    expect(Book::count())->toBe(1)
        ->and(Book::sole()->title)->toBe('Dune') // the first logger's metadata wins
        ->and(ReadEntry::count())->toBe(2);
});

it('returns only the acting user\'s entries, newest finished first', function () {
    $mine = User::factory()->create();
    $other = User::factory()->create();

    service()->logBook($mine->id, logData('ol:1', 'Older', '2024-01-01'));
    service()->logBook($mine->id, logData('ol:2', 'Newer', '2024-06-01'));
    service()->logBook($other->id, logData('ol:3', 'Theirs', '2024-07-01'));

    expect(service()->getMyBooks($mine->id)->map(fn ($e) => $e->book->title)->all())
        ->toBe(['Newer', 'Older']);
});

it('breaks a finished-date tie by newest created', function () {
    $user = User::factory()->create();
    $earlier = Book::factory()->create(['title' => 'Earlier-created', 'open_library_id' => 'ol:1']);
    $later = Book::factory()->create(['title' => 'Later-created', 'open_library_id' => 'ol:2']);

    $a = ReadEntry::factory()->for($user)->for($earlier)->create(['finished_at' => '2024-05-05']);
    $a->created_at = '2024-05-05 09:00:00';
    $a->save();

    $b = ReadEntry::factory()->for($user)->for($later)->create(['finished_at' => '2024-05-05']);
    $b->created_at = '2024-05-05 10:00:00';
    $b->save();

    expect(service()->getMyBooks($user->id)->map(fn ($e) => $e->book->title)->all())
        ->toBe(['Later-created', 'Earlier-created']);
});

it('matches titles case-insensitively, for the acting user only', function () {
    $mine = User::factory()->create();
    $other = User::factory()->create();

    service()->logBook($mine->id, logData('ol:1', 'Dune', '2024-01-01'));
    service()->logBook($other->id, logData('ol:2', 'Dune Messiah', '2024-02-01'));

    $results = service()->checkIfRead($mine->id, 'dUnE');

    expect($results)->toHaveCount(1)
        ->and($results->first()->book->title)->toBe('Dune');
});

it('returns nothing for a blank lookup', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01'));

    expect(service()->checkIfRead($user->id, '   '))->toBeEmpty();
});

it('does not let a bare percent match the whole library', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01'));

    expect(service()->checkIfRead($user->id, '%'))->toBeEmpty();
});

it('matches a percent against titles that literally contain one, and only those', function () {
    // Stronger than the .NET case, which only asserts that % finds nothing in a
    // library with no percent signs in it. This one proves the escape works in both
    // directions: the literal is found, and the wildcard meaning is gone.
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01'));
    service()->logBook($user->id, logData('ol:2', '50% Off', '2024-02-01'));

    $results = service()->checkIfRead($user->id, '%');

    expect($results)->toHaveCount(1)
        ->and($results->first()->book->title)->toBe('50% Off');
});

it('treats an underscore literally rather than as a single-character wildcard', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'A_B', '2024-01-01'));

    expect(service()->checkIfRead($user->id, 'A_B'))->toHaveCount(1)
        ->and(service()->checkIfRead($user->id, 'AxB'))->toBeEmpty();
});

it('updates the per-user fields but not the shared book title', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01', rating: 3));
    $entryId = ReadEntry::sole()->id;

    $updated = service()->updateReadEntry($user->id, $entryId, new UpdateReadEntryData(
        format: Format::Audiobook,
        finishedAt: '2024-03-03',
        rating: 5,
    ));

    $entry = ReadEntry::with('book')->sole();

    expect($updated)->toBeTrue()
        ->and($entry->format)->toBe(Format::Audiobook)
        ->and($entry->finished_at->toDateString())->toBe('2024-03-03')
        ->and($entry->rating)->toBe(5)
        ->and($entry->book->title)->toBe('Dune'); // not editable through the entry
});

it('clears a rating when null is submitted', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01', rating: 4));
    $entryId = ReadEntry::sole()->id;

    service()->updateReadEntry($user->id, $entryId, new UpdateReadEntryData(
        format: Format::Book,
        finishedAt: '2024-01-01',
        rating: null,
    ));

    expect(ReadEntry::sole()->rating)->toBeNull();
});

it('refuses to update another user\'s entry, and leaves it untouched', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    service()->logBook($owner->id, logData('ol:1', 'Dune', '2024-01-01', rating: 3));
    $entryId = ReadEntry::sole()->id;

    $updated = service()->updateReadEntry($stranger->id, $entryId, new UpdateReadEntryData(
        format: Format::Ebook,
        finishedAt: '2024-09-09',
        rating: 0,
    ));

    $entry = ReadEntry::with('book')->sole();

    expect($updated)->toBeFalse() // 404, not 403
        ->and($entry->book->title)->toBe('Dune')
        ->and($entry->rating)->toBe(3);
});

it('deletes an entry but keeps the catalogue book', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01'));
    $entryId = ReadEntry::sole()->id;

    expect(service()->deleteReadEntry($user->id, $entryId))->toBeTrue()
        ->and(ReadEntry::count())->toBe(0)
        ->and(Book::count())->toBe(1);
});

it('refuses to delete another user\'s entry', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    service()->logBook($owner->id, logData('ol:1', 'Dune', '2024-01-01'));
    $entryId = ReadEntry::sole()->id;

    expect(service()->deleteReadEntry($stranger->id, $entryId))->toBeFalse()
        ->and(ReadEntry::count())->toBe(1); // untouched
});

it('counts total and per-format stats for one user only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    service()->logBook($user->id, logData('ol:1', 'A', '2024-01-01', Format::Book));
    service()->logBook($user->id, logData('ol:2', 'B', '2024-02-01', Format::Book));
    service()->logBook($user->id, logData('ol:3', 'C', '2024-03-01', Format::Audiobook));
    service()->logBook($other->id, logData('ol:4', 'D', '2024-04-01', Format::Ebook));

    $stats = service()->getAccountStats($user->id);

    expect($stats->totalBooks)->toBe(3)
        ->and($stats->countFor(Format::Book))->toBe(2)
        ->and($stats->countFor(Format::Audiobook))->toBe(1)
        ->and($stats->countFor(Format::Ebook))->toBe(0); // the other user's only
});

it('returns the twenty newest reads across all users on the public feed', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create(['title' => 'Catalogue', 'open_library_id' => 'ol:shared']);

    foreach (range(0, 24) as $i) {
        $entry = ReadEntry::factory()->for($user)->for($book)->create([
            'finished_at' => now()->subDays(100)->addDays($i)->toDateString(),
        ]);
        // Stamped explicitly so the ordering is deterministic.
        $entry->created_at = now()->subDays(100)->startOfDay()->addMinutes($i);
        $entry->save();
    }

    $feed = service()->getRecentPublicReads();

    expect($feed)->toHaveCount(20)
        ->and($feed->first()->createdAt->toDateTimeString())
        ->toBe(now()->subDays(100)->startOfDay()->addMinutes(24)->toDateTimeString())
        ->and($feed->every(fn ($read) => $read->title === 'Catalogue'))->toBeTrue();
});

it('caches the public feed until a write evicts it', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'First', '2024-01-01'));

    expect(service()->getRecentPublicReads())->toHaveCount(1); // populates the cache

    // Insert out of band, so nothing evicts the cache.
    $book = Book::factory()->create(['open_library_id' => 'ol:2']);
    DB::table('read_entries')->insert([
        'user_id' => $user->id,
        'book_id' => $book->id,
        'format' => Format::Book->value,
        'finished_at' => '2024-02-01',
        'rating' => null,
        'created_at' => now()->toDateTimeString(),
    ]);

    expect(service()->getRecentPublicReads())->toHaveCount(1); // still served from cache

    // A write through the service evicts it, so the next read sees everything.
    service()->logBook($user->id, logData('ol:3', 'Third', '2024-03-01'));

    expect(service()->getRecentPublicReads())->toHaveCount(3);
});

it('rejects logging the same book on the same date twice', function () {
    $user = User::factory()->create();
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01'));

    expect(fn () => service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01')))
        ->toThrow(DuplicateReadEntryException::class);
});

it('allows two users to log the same book on the same date', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    service()->logBook($first->id, logData('ol:race', 'Dune', '2024-01-01'));
    service()->logBook($second->id, logData('ol:race', 'Dune', '2024-01-01'));

    expect(Book::where('open_library_id', 'ol:race')->count())->toBe(1)
        ->and(ReadEntry::count())->toBe(2);
});

it('recovers when it loses the race to create a shared catalogue book', function () {
    // The .NET suite proves this with two real connections logging concurrently
    // (LogBookAsync_handles_a_concurrent_first_log_of_the_same_book). PHP has no
    // threads to do that with, so the race is forced instead: the winning row is
    // dropped in between the service's existence check and its insert, which is
    // exactly the window the recovery code exists for.
    //
    // The hook is a query listener that fires once, right after the SELECT that
    // finds nothing. An earlier version used a Book::creating model event, which
    // fires inside create() and therefore inside the savepoint the service now
    // opens around the insert, so the simulated winner was rolled back together
    // with the loser and the recovery had nothing to find. A concurrent writer's
    // row would already be committed, and this arranges the same thing.
    $user = User::factory()->create();

    $planted = false;
    DB::listen(function ($query) use (&$planted) {
        if ($planted || ! str_contains($query->sql, 'from "books"')) {
            return;
        }

        $planted = true;
        DB::table('books')->insert([
            'title' => 'Winner',
            'open_library_id' => 'ol:race',
            'created_at' => now()->toDateTimeString(),
        ]);
    });

    service()->logBook($user->id, logData('ol:race', 'Loser', '2024-01-01'));

    $entry = ReadEntry::with('book')->sole();

    expect(Book::where('open_library_id', 'ol:race')->count())->toBe(1)
        ->and($entry->book->title)->toBe('Winner'); // reused the row that won
});

it('exposes no user fields at all on the public feed projection', function () {
    // .NET counterpart: the comment on PublicReadDto saying it "deliberately carries
    // no user fields". A comment is not a guarantee, so this asserts the shape.
    $user = User::factory()->create(['name' => 'Very Private Person']);
    service()->logBook($user->id, logData('ol:1', 'Dune', '2024-01-01'));

    $read = service()->getRecentPublicReads()->first();

    $properties = array_keys(get_object_vars($read));

    expect($properties)->toBe(['title', 'author', 'coverUrl', 'format', 'createdAt', 'rating'])
        ->and(json_encode($read))->not->toContain('Very Private Person');
});
