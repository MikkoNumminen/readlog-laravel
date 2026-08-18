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

        return collect($response->json('items') ?? [])
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

        $info = $volume['volumeInfo'] ?? [];

        return new BookDetails(
            title: $info['title'] ?? $title,
            authors: $info['authors'] ?? ($author !== null ? [$author] : []),
            description: $info['description'] ?? null,
            categories: $info['categories'] ?? [],
            publisher: $info['publisher'] ?? null,
            publishedDate: $info['publishedDate'] ?? null,
            pageCount: isset($info['pageCount']) ? (int) $info['pageCount'] : null,
            coverUrl: $this->toHttps($info['imageLinks']['thumbnail'] ?? null),
            language: $info['language'] ?? null,
            previewLink: $info['previewLink'] ?? null,
            infoLink: $info['infoLink'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapSearchResult(array $item): BookSearchResult
    {
        $info = $item['volumeInfo'] ?? [];
        $subtitle = $info['subtitle'] ?? null;

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
            author: $info['authors'][0] ?? null,
            firstPublishYear: $this->parseYear($info['publishedDate'] ?? null),
            pageCount: isset($info['pageCount']) ? (int) $info['pageCount'] : null,
            coverUrl: $this->toHttps($info['imageLinks']['thumbnail'] ?? null),
        );
    }

    /** Google often serves cover thumbnails over http; upgrade to https. */
    private function toHttps(?string $url): ?string
    {
        return $url === null ? null : str_replace('http:', 'https:', $url);
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
