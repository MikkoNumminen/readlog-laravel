<?php

namespace App\Services;

use App\Enums\Format;
use App\Exceptions\DuplicateReadEntryException;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Support\AccountStats;
use App\Support\BookSummary;
use App\Support\LibraryEntry;
use App\Support\LogBookData;
use App\Support\PublicRead;
use App\Support\UpdateReadEntryData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The reading-log domain: logging finished books, the user's library, the
 * "have I read this?" lookup, edit and delete, account stats, and the public feed.
 *
 * .NET counterpart: Services/ReadLogService.cs.
 *
 * Methods take the acting user id explicitly, exactly as the .NET service does.
 * Controllers resolve the user and pass it in, which keeps this class free of HTTP,
 * session and request concerns and makes every ownership rule testable without a
 * browser.
 *
 * One structural difference from the source: there is no IReadLogService interface.
 * The .NET version has one so the class can be faked in tests and registered with
 * the container by contract. Here the container resolves the concrete class by
 * name, and the tests run against a real in-memory SQLite database rather than a
 * mock, so the interface would have had exactly one implementation and no caller
 * that cared which. See DECISIONS.md.
 */
class ReadLogService
{
    private const PUBLIC_FEED_SIZE = 20;

    private const PUBLIC_FEED_CACHE_KEY = 'public-feed';

    private const PUBLIC_FEED_CACHE_SECONDS = 60;

    /**
     * @throws DuplicateReadEntryException when this user already logged this book on this date
     */
    public function logBook(int $userId, LogBookData $data): void
    {
        $book = $this->getOrCreateBook($data);

        try {
            // Wrapped in a savepoint when a transaction is already open (see
            // getOrCreateBook for why); a no-op at transaction level zero.
            ReadEntry::query()->withSavepointIfNeeded(fn () => ReadEntry::create([
                'user_id' => $userId,
                'book_id' => $book->id,
                'format' => $data->format,
                'finished_at' => $data->finishedAt,
                'rating' => $data->rating,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // The unique (user, book, finished-on) index may have rejected a duplicate.
            // Confirm by re-querying; if it really is a duplicate, surface a domain
            // error, otherwise rethrow so a genuine failure is not mislabelled.
            $alreadyLogged = ReadEntry::query()
                ->where('user_id', $userId)
                ->where('book_id', $book->id)
                ->where('finished_at', $data->finishedAt)
                ->exists();

            if ($alreadyLogged) {
                throw new DuplicateReadEntryException;
            }

            throw $e;
        }

        $this->forgetPublicFeed(); // the public feed changed
    }

    /**
     * @return Collection<int, LibraryEntry>
     */
    public function getMyBooks(int $userId): Collection
    {
        return $this->entryQuery()
            ->where('user_id', $userId)
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->get()
            ->map($this->toLibraryEntry(...));
    }

    /**
     * @return LibraryEntry|null null if the entry does not exist or is not owned by this user
     */
    public function getEntry(int $userId, int $entryId): ?LibraryEntry
    {
        $entry = $this->entryQuery()
            ->where('id', $entryId)
            ->where('user_id', $userId)
            ->first();

        return $entry === null ? null : $this->toLibraryEntry($entry);
    }

    /**
     * The "have I read this?" lookup over the user's own library.
     *
     * @return Collection<int, LibraryEntry>
     */
    public function checkIfRead(int $userId, string $query): Collection
    {
        if (trim($query) === '') {
            return collect();
        }

        // Case-insensitive "contains". The .NET version writes a plain LIKE and
        // relies on SQLite's LIKE being ASCII case-insensitive by default. That
        // assumption does not survive a change of database: Postgres LIKE is
        // case-sensitive, and the case-insensitive ILIKE is Postgres-only. Lower-
        // casing both sides is plain SQL that behaves the same everywhere. On
        // SQLite, lower() is ASCII-only, exactly like its LIKE, so nothing changes
        // there; on Postgres it is Unicode-aware, which is a small improvement.
        //
        // The user's own % / _ / backslash are escaped so they match literally,
        // not as wildcards.
        $pattern = '%'.$this->escapeLike(mb_strtolower(trim($query))).'%';

        return $this->entryQuery()
            ->where('user_id', $userId)
            ->whereHas('book', fn (Builder $books) => $books->whereRaw(
                'lower(title) like ? escape '.self::LIKE_ESCAPE_LITERAL,
                [$pattern]
            ))
            ->orderByDesc('finished_at')
            ->get()
            ->map($this->toLibraryEntry(...));
    }

    /**
     * @return bool false if the entry does not exist or is not owned by this user (treat as 404)
     */
    public function updateReadEntry(int $userId, int $entryId, UpdateReadEntryData $data): bool
    {
        // Existence and ownership are one query on purpose: a non-owner gets the same
        // "not found" as a stranger, because the source returns 404, not 403.
        $entry = ReadEntry::query()
            ->where('id', $entryId)
            ->where('user_id', $userId)
            ->first();

        if ($entry === null) {
            return false;
        }

        // The shared catalogue Book is deliberately not editable here: a title edit
        // would rewrite the row for every user who logged that book.
        $entry->format = $data->format;
        $entry->finished_at = $data->finishedAt;
        $entry->rating = $data->rating; // null clears, 0 is a real rating
        $entry->save();

        $this->forgetPublicFeed();

        return true;
    }

    /**
     * @return bool false if the entry does not exist or is not owned by this user (treat as 404)
     */
    public function deleteReadEntry(int $userId, int $entryId): bool
    {
        $entry = ReadEntry::query()
            ->where('id', $entryId)
            ->where('user_id', $userId)
            ->first();

        if ($entry === null) {
            return false;
        }

        $entry->delete(); // the shared Book row is left intact
        $this->forgetPublicFeed();

        return true;
    }

    public function getAccountStats(int $userId): AccountStats
    {
        $formats = ReadEntry::query()
            ->where('user_id', $userId)
            ->selectRaw('format, count(*) as total')
            ->groupBy('format')
            ->pluck('total', 'format')
            ->map(fn ($total) => (int) $total)
            ->all();

        return new AccountStats(array_sum($formats), $formats);
    }

    /**
     * @return Collection<int, PublicRead>
     */
    public function getRecentPublicReads(): Collection
    {
        // A global hot read: cached briefly and evicted on any write. That is the
        // Laravel take on the .NET IMemoryCache plus an explicit Remove, which was
        // in turn the .NET take on the original Next.js updateTag.
        //
        // What is cached is plain arrays of scalars, not PublicRead objects, and that
        // is not a style preference. .NET's IMemoryCache holds a live object
        // reference, so caching a List<PublicReadDto> costs nothing and returns the
        // same instances. Every Laravel cache store except the in-process array one
        // serializes, and Laravel 13 ships config/cache.php with
        // 'serializable_classes' => false, meaning no class at all may be unserialized
        // back out of the cache (a hardening measure against gadget chains if APP_KEY
        // leaks). Caching objects therefore writes cleanly and then fails on the next
        // read with __PHP_Incomplete_Class. Keeping scalars in the cache keeps that
        // secure default intact instead of widening an allowlist to suit this app.
        $rows = Cache::remember(
            self::PUBLIC_FEED_CACHE_KEY,
            self::PUBLIC_FEED_CACHE_SECONDS,
            fn () => ReadEntry::query()
                ->with('book')
                ->orderByDesc('created_at')
                ->limit(self::PUBLIC_FEED_SIZE)
                ->get()
                ->map(fn (ReadEntry $entry) => [
                    'title' => $entry->book->title,
                    'author' => $entry->book->author,
                    'cover_url' => $entry->book->cover_url,
                    'format' => $entry->format->value,
                    'created_at' => $entry->created_at->toIso8601String(),
                    'rating' => $entry->rating,
                ])
                ->all()
        );

        return collect($rows)->map(fn (array $row) => new PublicRead(
            title: $row['title'],
            author: $row['author'],
            coverUrl: $row['cover_url'],
            format: Format::from($row['format']),
            createdAt: CarbonImmutable::parse($row['created_at']),
            rating: $row['rating'],
        ));
    }

    /**
     * Reuses the catalogue book for this provider id, or creates it. Tolerates a
     * concurrent creation losing the race on the unique open_library_id index.
     */
    private function getOrCreateBook(LogBookData $data): Book
    {
        $existing = Book::query()->where('open_library_id', $data->openLibraryId)->first();

        if ($existing !== null) {
            return $existing; // reuse as-is: the first logger's metadata wins
        }

        try {
            // The insert runs inside a savepoint whenever a transaction is already
            // open, and bare otherwise. This is what Laravel's own createOrFirst does,
            // and it exists for Postgres: after a constraint violation Postgres marks
            // the whole transaction as aborted, so the "look for the winner" query in
            // the catch block would itself fail with "current transaction is aborted".
            // Rolling back to a savepoint keeps the transaction usable. SQLite does
            // not need it, which is why the first version of this code, tested on
            // SQLite only, never noticed. In production there is no outer transaction
            // and the closure runs as-is.
            return Book::query()->withSavepointIfNeeded(fn () => Book::create([
                'open_library_id' => $data->openLibraryId,
                'title' => $data->title,
                'author' => $data->author,
                'cover_url' => $data->coverUrl,
                'page_count' => $data->pageCount,
                'first_publish_year' => $data->firstPublishYear,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // We may have lost a race to create the shared book. Look for the winner.
            $winner = Book::query()->where('open_library_id', $data->openLibraryId)->first();

            if ($winner === null) {
                // No winning row exists, so this was not the race we tolerate
                // (a locked database, say). Let the real failure surface.
                throw $e;
            }

            Log::info('Lost a race creating a book; reusing the existing row.', [
                'open_library_id' => $data->openLibraryId,
            ]);

            return $winner;
        }
    }

    /**
     * @return Builder<ReadEntry>
     */
    private function entryQuery(): Builder
    {
        return ReadEntry::query()->with('book');
    }

    private function toLibraryEntry(ReadEntry $entry): LibraryEntry
    {
        return new LibraryEntry(
            id: $entry->id,
            format: $entry->format,
            finishedAt: $entry->finished_at,
            rating: $entry->rating,
            book: new BookSummary(
                title: $entry->book->title,
                author: $entry->book->author,
                coverUrl: $entry->book->cover_url,
            ),
        );
    }

    /**
     * Escapes LIKE metacharacters so a search term is matched literally.
     *
     * .NET counterpart: ReadLogService.EscapeLike. Same three replacements, but the
     * escape character has to be declared to SQLite as well (see LIKE_ESCAPE_LITERAL);
     * EF Core's EF.Functions.Like takes it as a third argument.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * The SQL literal for the LIKE escape character: a single-quoted single backslash.
     * Built here rather than inlined so the layers of escaping stay readable, and kept
     * out of the bindings because SQLite wants ESCAPE as a literal, not a parameter.
     */
    private const LIKE_ESCAPE_LITERAL = "'\\'";

    private function forgetPublicFeed(): void
    {
        Cache::forget(self::PUBLIC_FEED_CACHE_KEY);
    }
}
