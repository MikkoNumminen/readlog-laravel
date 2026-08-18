<?php

namespace App\Support;

/**
 * A single normalised search hit, merged from Open Library and Google Books.
 *
 * .NET counterpart: the `BookSearchResult` record in Dtos/BookSearchResult.cs.
 *
 * `openLibraryId` is the provider-namespaced natural key used to find-or-create the
 * catalogue book: an Open Library work key (`/works/OL1W`), a `google:<id>`, or a
 * `manual:<token>` for a hand-entered book. The name is a leftover from when Open
 * Library was the only provider, and it is kept because it is the column name in
 * both apps' schemas and renaming it would break reading each other's databases.
 */
final readonly class BookSearchResult
{
    public function __construct(
        public string $openLibraryId,
        public string $title,
        public ?string $subtitle,
        public ?string $author,
        public ?int $firstPublishYear,
        public ?int $pageCount,
        public ?string $coverUrl,
    ) {}
}
