<?php

namespace App\Support;

use App\Enums\Format;
use Carbon\CarbonImmutable;

/**
 * A read entry as shown in the user's library, and in the "have I read this?" lookup.
 *
 * .NET counterpart: the `LibraryEntryDto` record in Dtos/LibraryDtos.cs.
 */
final readonly class LibraryEntry
{
    public function __construct(
        public int $id,
        public Format $format,
        public CarbonImmutable $finishedAt,
        public ?int $rating,
        public BookSummary $book,
    ) {}
}
