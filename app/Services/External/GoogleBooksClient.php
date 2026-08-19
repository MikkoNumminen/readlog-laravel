<?php

namespace App\Services\External;

use App\Support\BookDetails;
use App\Support\BookSearchResult;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Searches Google Books and fetches rich volume details.
 *
 * .NET counterpart: Services/External/GoogleBooksClient.cs.
 *
 * Requires an API key. When it is missing, or the query is empty, the call is
 * skipped and an empty result or null comes back. Unlike Open Library, a
 * non-success response degrades to empty or null rather than throwing. The source
 * has that asymmetry and it is kept, because it is inherited from the original
 * Next.js app's behaviour.
 */
class GoogleBooksClient
{
    public function shouldSearch(string $query): bool
    {
        return $this->apiKey() !== null && trim($query) !== '';
    }

    /**
     * Queues the search on a request pool. The return value is a promise; the pool
     * resolves all of them together.
     */
    public function requestSearch(PendingRequest $request, string $query): Response|PromiseInterface
    {
        return $request
            ->timeout($this->timeout())
            ->baseUrl($this->baseUrl())
            ->get('volumes', [
                'q' => $query,
                'maxResults' => $this->limit(),
                'key' => $this->apiKey(),
            ]);
    }

    /**
     * @return Collection<int, BookSearchResult>
     */
    public function parseSearch(Response $response): Collection
    {
        if ($response->failed()) {
            return collect(); // degrade, do not throw
        }

        // Provider JSON is untrusted in shape as well as content. One malformed
        // element must not take the other results down with it, so non-array
        // items are skipped rather than allowed to raise a TypeError that
        // BookSearchService::settle would catch at the whole-response level.
        $items = $response->json('items');

        return collect(is_array($items) ? $items : [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => $this->mapSearchResult($item))
            ->values();
    }

    /**
     * The detail lookup is a single request with nothing to run alongside it, so
     * unlike the search it is a plain synchronous call.
     */
    public function getDetails(string $title, ?string $author): ?BookDetails
    {
        if ($this->apiKey() === null) {
            return null;
        }

        $query = trim(implode(' ', array_filter([trim($title), trim((string) $author)])));

        if ($query === '') {
            return null;
        }

        $response = Http::timeout($this->timeout())
            ->baseUrl($this->baseUrl())
            ->get('volumes', [
                'q' => $query,
                'maxResults' => 1,
                'key' => $this->apiKey(),
            ]);

        if ($response->failed()) {
            return null;
        }

        $volume = $response->json('items.0');

        if (! is_array($volume)) {
            return null;
        }

        $info = is_array($volume['volumeInfo'] ?? null) ? $volume['volumeInfo'] : [];

        $authors = $this->stringList($info['authors'] ?? null);

        return new BookDetails(
            title: $this->stringOrNull($info['title'] ?? null) ?? $title,
            authors: $authors !== [] ? $authors : ($author !== null ? [$author] : []),
            description: $this->stringOrNull($info['description'] ?? null),
            categories: $this->stringList($info['categories'] ?? null),
            publisher: $this->stringOrNull($info['publisher'] ?? null),
            publishedDate: $this->stringOrNull($info['publishedDate'] ?? null),
            pageCount: is_numeric($info['pageCount'] ?? null) ? (int) $info['pageCount'] : null,
            coverUrl: $this->toHttps($this->stringOrNull($info['imageLinks']['thumbnail'] ?? null)),
            language: $this->stringOrNull($info['language'] ?? null),
            previewLink: $this->stringOrNull($info['previewLink'] ?? null),
            infoLink: $this->stringOrNull($info['infoLink'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapSearchResult(array $item): BookSearchResult
    {
        $info = is_array($item['volumeInfo'] ?? null) ? $item['volumeInfo'] : [];
        $subtitle = $this->stringOrNull($info['subtitle'] ?? null);

        // If the volume is part of a series, surface that as the subtitle.
        $seriesNumber = $info['seriesInfo']['bookDisplayNumber'] ?? null;

        if ($seriesNumber !== null && $seriesNumber !== '') {
            $subtitle = ($subtitle === null || $subtitle === '')
                ? "Book {$seriesNumber}"
                : "Book {$seriesNumber} · {$subtitle}";
        }

        return new BookSearchResult(
            openLibraryId: 'google:'.($item['id'] ?? ''),
            title: $info['title'] ?? '',
            subtitle: $subtitle,
            author: $this->stringList($info['authors'] ?? null)[0] ?? null,
            firstPublishYear: $this->parseYear($info['publishedDate'] ?? null),
            pageCount: isset($info['pageCount']) ? (int) $info['pageCount'] : null,
            coverUrl: $this->toHttps($this->stringOrNull($info['imageLinks']['thumbnail'] ?? null)),
        );
    }

    /**
     * Google often serves cover thumbnails over http; upgrade to https.
     *
     * Only the leading scheme. The .NET port used Replace("http:", "https:"),
     * which rewrites every occurrence and would corrupt an embedded URL in a query
     * string; the JavaScript original's String.replace touched the first occurrence
     * only, which for a URL means the scheme. This follows the original.
     */
    private function toHttps(?string $url): ?string
    {
        return $url === null ? null : (preg_replace('/^http:/', 'https:', $url) ?? $url);
    }

    /**
     * A JSON value that should be a list of strings, reduced to exactly that.
     * A bare string becomes a one-element list; anything else non-string is dropped.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item) => is_string($item) && $item !== ''));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Takes the leading year out of a Google "publishedDate" such as "2014-09-01".
     *
     * Mirrors the original's `parseInt(date.slice(0, 4), 10) || null`, which the .NET
     * port went to some trouble to reproduce: from the first four characters, take
     * the leading run of digits, so MARC-style fuzzy dates like "198?" and "19uu"
     * still yield 198 and 19, and treat no digits or 0 as null.
     *
     * PHP's (int) cast on a string happens to have the same leading-digits behaviour
     * as JavaScript's parseInt, which C# had to hand-roll with TakeWhile.
     */
    private function parseYear(?string $publishedDate): ?int
    {
        if ($publishedDate === null || $publishedDate === '') {
            return null;
        }

        $slice = substr($publishedDate, 0, 4);
        $year = (int) ltrim($slice);

        return $year !== 0 ? $year : null;
    }

    private function apiKey(): ?string
    {
        $key = config('services.google_books.api_key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    private function baseUrl(): string
    {
        return (string) config('services.google_books.base_url');
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
