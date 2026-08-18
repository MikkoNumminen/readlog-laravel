<?php

namespace App\Support;

use App\Enums\Format;
use Carbon\CarbonImmutable;

/**
 * An entry on the public "recently read" feed.
 *
 * .NET counterpart: the `PublicReadDto` record in Dtos/LibraryDtos.cs. It carries
 * no user fields, deliberately: the feed never exposes who read what. That is the
 * reason this projection exists at all rather than the view being handed
 * ReadEntry models, which would put the reader one `->user` away from being
 * rendered by accident.
 */
final readonly class PublicRead
{
    public function __construct(
        public string $title,
        public ?string $author,
        public ?string $coverUrl,
        public Format $format,
        public CarbonImmutable $createdAt,
        public ?int $rating,
    ) {}
}
