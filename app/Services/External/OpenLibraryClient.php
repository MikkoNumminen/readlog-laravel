<?php

namespace App\Services\External;

use App\Support\BookSearchResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Searches the Open Library catalogue.
 *
 * .NET counterpart: Services/External/OpenLibraryClient.cs.
 *
 * Behaviour carried over exactly, including the asymmetry with Google Books: a
 * non-success response here **throws**, and BookSearchService degrades that to "no
 * Open Library results". Google Books swallows its own failures instead. That is not
 * an inconsistency anyone should tidy up; it is what the original Next.js app did,
 * and the .NET port kept it, so this one does too.
 *
 * The split between requestSearch() and parseSearch() is the one structural change.
 * The .NET client does both in one async method, because .NET can await two of them
 * concurrently with Task.WhenAll. PHP has no await, so the concurrent fetch is done
 * with Laravel's Http::pool, which needs the request handed to it before any
 * response exists. See BookSearchService.
 */
class OpenLibraryClient
{
    /** Only the fields the app needs, to keep the response small. */
    private const FIELDS = 'key,title,subtitle,author_name,first_publish_year,number_of_pages_median,cover_i';

    /** An empty query is a no-op, exactly as in the source. */
    public function shouldSearch(string $query): bool
    {
        return trim($query) !== '';
    }

    /**
     * Queues the search on a request pool. The pool hands over a PendingRequest and
     * gets back a promise, which it resolves alongside the other providers'.
     */
    public function requestSearch(PendingRequest $request, string $query): Response|PromiseInterface
    {
        return $request
            ->timeout($this->timeout())
            ->withHeaders(['User-Agent' => 'ReadLog/1.0 (+https://github.com/MikkoNumminen/readlog-laravel)'])
            ->baseUrl($this->baseUrl())
            ->get('search.json', [
                'q' => $query,
                'limit' => $this->limit(),
                'fields' => self::FIELDS,
            ]);
    }

    /**
     * @return Collection<int, BookSearchResult>
     *
     * @throws RuntimeException on a non-success response
     */
    public function parseSearch(Response $response): Collection
    {
        if ($response->failed()) {
            throw new RuntimeException("Open Library API error ({$response->status()}).");
        }

        // Same rule as the Google client: a malformed element is skipped, not
        // allowed to sink the whole response.
        return collect($response->json('docs') ?? [])
            ->filter(fn ($doc) => is_array($doc))
            ->map(fn (array $doc) => $this->map($doc))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function map(array $doc): BookSearchResult
    {
        $coverId = $doc['cover_i'] ?? null;

        return new BookSearchResult(
            openLibraryId: $doc['key'] ?? '',
            title: $doc['title'] ?? '',
            subtitle: $doc['subtitle'] ?? null,
            // First author only, as in the source. author_name is documented as a
            // list; if it ever arrives as a bare string, PHP's string offset would
            // silently hand back its first character, so the shape is checked.
            author: is_array($doc['author_name'] ?? null)
                ? (is_string($doc['author_name'][0] ?? null) ? $doc['author_name'][0] : null)
                : (is_string($doc['author_name'] ?? null) ? $doc['author_name'] : null),
            firstPublishYear: isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
            pageCount: isset($doc['number_of_pages_median']) ? (int) $doc['number_of_pages_median'] : null,
            coverUrl: $coverId === null ? null : "https://covers.openlibrary.org/b/id/{$coverId}-M.jpg",
        );
    }

    private function baseUrl(): string
    {
        return (string) config('services.open_library.base_url');
    }

    private function timeout(): int
    {
        return (int) config('services.book_search.timeout');
    }

    private function limit(): int
    {
        return (int) config('services.book_search.limit');
    }
}
