<?php

namespace App\Services;

use App\Services\External\GoogleBooksClient;
use App\Services\External\OpenLibraryClient;
use App\Support\BookSearchResult;
use App\Support\Redact;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Searches every book provider and returns a merged, de-duplicated list.
 *
 * .NET counterpart: Services/BookSearchService.cs.
 *
 * Three behaviours, all inherited from the original Next.js app through the .NET
 * port, and all preserved here:
 *
 *  1. Both providers are queried concurrently.
 *  2. Either provider failing must not sink the other. The original used
 *     Promise.allSettled; .NET wrapped each task in a try/catch and used
 *     Task.WhenAll; here each response is unwrapped in its own try/catch after the
 *     pool resolves.
 *  3. Results are concatenated Open Library first, then de-duplicated by normalised
 *     title plus author, keeping whichever duplicate carries more metadata. Ties
 *     keep the first seen, which is what makes the Open-Library-first ordering
 *     matter.
 *
 * # On "concurrently"
 *
 * This is the one place where the two languages genuinely differ rather than just
 * spelling the same thing differently. .NET starts two Tasks and awaits both:
 *
 *     var results = await Task.WhenAll(openLibraryTask, googleTask);
 *
 * PHP has no await and no threads. What Laravel offers is Http::pool, which hands
 * the requests to Guzzle's curl_multi handle and blocks until all of them come
 * back. The wall-clock behaviour is the same, two requests in flight at once rather
 * than one after the other, but the shape is different in a way that leaks into the
 * design: a pool needs every request handed over before any response exists, so the
 * clients had to be split into requestSearch() and parseSearch() rather than one
 * method that does both. That split is the visible cost of not having await.
 *
 * It also means there is no cancellation. Every .NET method here takes a
 * CancellationToken and the source is careful to let a caller-initiated cancel
 * propagate rather than degrade to empty results. PHP request handling has nothing
 * to cancel from, so those parameters have no counterpart and are simply gone.
 */
class BookSearchService
{
    private const OPEN_LIBRARY = 'open_library';

    private const GOOGLE_BOOKS = 'google_books';

    public function __construct(
        private readonly OpenLibraryClient $openLibrary,
        private readonly GoogleBooksClient $googleBooks,
    ) {}

    /**
     * @return Collection<int, BookSearchResult>
     */
    public function search(string $query): Collection
    {
        $wantsOpenLibrary = $this->openLibrary->shouldSearch($query);
        $wantsGoogleBooks = $this->googleBooks->shouldSearch($query);

        if (! $wantsOpenLibrary && ! $wantsGoogleBooks) {
            return collect();
        }

        $responses = Http::pool(function (Pool $pool) use ($query, $wantsOpenLibrary, $wantsGoogleBooks) {
            $requests = [];

            if ($wantsOpenLibrary) {
                $requests[] = $this->openLibrary->requestSearch($pool->as(self::OPEN_LIBRARY), $query);
            }

            if ($wantsGoogleBooks) {
                $requests[] = $this->googleBooks->requestSearch($pool->as(self::GOOGLE_BOOKS), $query);
            }

            return $requests;
        });

        // Open Library first, then Google Books: the order decides the de-dup tie-break.
        $results = $this
            ->settle(
                'Open Library',
                $responses[self::OPEN_LIBRARY] ?? null,
                fn (Response $response) => $this->openLibrary->parseSearch($response),
            )
            ->concat($this->settle(
                'Google Books',
                $responses[self::GOOGLE_BOOKS] ?? null,
                fn (Response $response) => $this->googleBooks->parseSearch($response),
            ));

        return $this->deduplicate($results);
    }

    /**
     * The Promise.allSettled half: turn one provider's outcome into results, and
     * turn any failure into an empty list plus a log line.
     *
     * A pooled request that never completed comes back as a Throwable rather than a
     * Response, which is why the type is checked before it is handed on.
     *
     * @param  callable(Response): Collection<int, BookSearchResult>  $parse
     * @return Collection<int, BookSearchResult>
     */
    private function settle(string $source, mixed $outcome, callable $parse): Collection
    {
        if ($outcome === null) {
            return collect(); // provider was skipped, not failed
        }

        try {
            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $parse($outcome);
        } catch (Throwable $e) {
            Log::warning($source.' search failed; continuing without its results.', [
                'reason' => $this->redact($e->getMessage()),
            ]);

            return collect();
        }
    }

    /**
     * Removes the Google Books API key from anything about to be logged.
     *
     * Guzzle puts the full request URL into a connection-failure message, and the
     * key travels in the query string because that is the only way Google Books
     * accepts it. So a DNS blip or a timeout writes the credential into
     * storage/logs. The .NET version logs the exception too, but its
     * HttpRequestException does not carry the URI, so the problem does not arise
     * there and there is nothing in the source to port. Found by triggering a
     * connection failure on purpose and reading what came out.
     */
    private function redact(string $message): string
    {
        return Redact::apiKey($message);
    }

    /**
     * Collapses duplicates that share a normalised title plus author key, keeping the
     * entry with the most metadata. Ties keep the first seen, so the
     * Open-Library-first ordering wins.
     *
     * @param  Collection<int, BookSearchResult>  $results
     * @return Collection<int, BookSearchResult>
     */
    private function deduplicate(Collection $results): Collection
    {
        /** @var array<int, BookSearchResult> $ordered */
        $ordered = [];
        /** @var array<string, int> $indexByKey */
        $indexByKey = [];

        foreach ($results as $book) {
            $key = $this->normalise($book->title).'|'.$this->normalise($book->author ?? '');

            if (! array_key_exists($key, $indexByKey)) {
                $indexByKey[$key] = count($ordered);
                $ordered[] = $book;

                continue;
            }

            $index = $indexByKey[$key];

            if ($this->score($book) > $this->score($ordered[$index])) {
                $ordered[$index] = $book; // upgrade in place, keeping the original position
            }
        }

        return collect($ordered);
    }

    /**
     * How much metadata a hit carries. Deliberately crude and deliberately identical
     * to the source: a cover is worth one, a page count is worth one, nothing else
     * counts. Note what this means in practice: the winner is whichever record is
     * richer, not whichever is more correct, so a conflicting title or year is
     * resolved by "who else had a cover", not by any judgement about the data.
     */
    private function score(BookSearchResult $book): int
    {
        return ($book->coverUrl !== null ? 1 : 0) + ($book->pageCount !== null ? 1 : 0);
    }

    /**
     * .NET counterpart: the [GeneratedRegex("[^a-z0-9]")] source-generated matcher.
     *
     * Both strip everything that is not an ASCII letter or digit after lower-casing,
     * which means the key for "Dune" and "Dune!" is the same, and so is the key for
     * two books whose titles differ only in punctuation or spacing. It also means
     * non-Latin titles collapse to an empty key, which is a real weakness shared
     * with the source and written up in MIGRATION.md rather than quietly fixed.
     */
    private function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($value)) ?? '';
    }
}
