<?php

namespace App\Support;

use App\Enums\Format;
use App\Models\ReadEntry;
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

    /** The entry must have its book loaded. */
    public static function fromModel(ReadEntry $entry): self
    {
        return new self(
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
}
