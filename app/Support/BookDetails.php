<?php

namespace App\Support;

/**
 * Rich book metadata fetched from Google Books for the detail view. Every field is
 * best effort: any of them may be missing depending on the volume.
 *
 * .NET counterpart: the `BookDetails` record in Dtos/BookDetails.cs.
 */
final readonly class BookDetails
{
    /**
     * @param  list<string>  $authors
     * @param  list<string>  $categories
     */
    public function __construct(
        public string $title,
        public array $authors,
        public ?string $description,
        public array $categories,
        public ?string $publisher,
        public ?string $publishedDate,
        public ?int $pageCount,
        public ?string $coverUrl,
        public ?string $language,
        public ?string $previewLink,
        public ?string $infoLink,
    ) {}

    /**
     * The cache stores scalars only, never objects. See the note on
     * ReadLogService::getRecentPublicReads: Laravel 13 ships
     * 'serializable_classes' => false, so an object written to the cache comes back
     * as __PHP_Incomplete_Class.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'authors' => $this->authors,
            'description' => $this->description,
            'categories' => $this->categories,
            'publisher' => $this->publisher,
            'published_date' => $this->publishedDate,
            'page_count' => $this->pageCount,
            'cover_url' => $this->coverUrl,
            'language' => $this->language,
            'preview_link' => $this->previewLink,
            'info_link' => $this->infoLink,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            title: $row['title'],
            authors: $row['authors'],
            description: $row['description'],
            categories: $row['categories'],
            publisher: $row['publisher'],
            publishedDate: $row['published_date'],
            pageCount: $row['page_count'],
            coverUrl: $row['cover_url'],
            language: $row['language'],
            previewLink: $row['preview_link'],
            infoLink: $row['info_link'],
        );
    }
}
