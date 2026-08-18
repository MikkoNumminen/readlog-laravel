<?php

namespace App\Services;

use App\Services\External\GoogleBooksClient;
use App\Support\BookDetails;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches, and caches, rich book details for the detail view.
 *
 * .NET counterpart: Services/BookDetailsService.cs.
 *
 * Details are effectively immutable, so a long TTL mirrors the original's
 * unstable_cache and the .NET port's 30 days. Only successful lookups are cached,
 * so a missing API key or a transient failure is retried next time rather than
 * remembered as "no such book" for a month.
 */
class BookDetailsService
{
    private const CACHE_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(private readonly GoogleBooksClient $googleBooks) {}

    public function getDetails(string $title, ?string $author): ?BookDetails
    {
        $key = $this->cacheKey($title, $author);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return BookDetails::fromArray($cached);
        }

        $details = $this->googleBooks->getDetails($title, $author);

        if ($details !== null) {
            // Arrays, not the object. See the note on
            // ReadLogService::getRecentPublicReads for why an object here would
            // write cleanly and then fail on the next read.
            Cache::put($key, $details->toArray(), self::CACHE_SECONDS);
        }

        return $details;
    }

    /**
     * .NET counterpart: the tuple cache key ("book-details", title, author), whose
     * structural equality sidesteps the delimiter ambiguity a "{title}|{author}"
     * string would have. PHP cache keys are strings, so the ambiguity has to be
     * removed some other way: hashing a JSON encoding of the parts keeps a title
     * containing a separator from colliding with a different title-plus-author.
     *
     * Trimming and lower-casing lets case and whitespace variants of the same book
     * share one entry, as in the source.
     */
    private function cacheKey(string $title, ?string $author): string
    {
        $parts = [
            mb_strtolower(trim($title)),
            $author === null ? null : mb_strtolower(trim($author)),
        ];

        return 'book-details:'.hash('xxh128', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
