<?php

namespace App\Support;

use App\Enums\Format;

/**
 * Validated input for logging a finished book.
 *
 * .NET counterpart: Dtos/LogBookRequest.cs. The split is deliberate and mirrors the
 * source: the validation rules live in the HTTP layer
 * (App\Http\Requests\LogBookRequest), and what reaches the service is a plain value
 * object with no request, session or container attached.
 */
final readonly class LogBookData
{
    public function __construct(
        public string $openLibraryId,
        public string $title,
        public ?string $author,
        public ?string $coverUrl,
        public ?int $pageCount,
        public ?int $firstPublishYear,
        public Format $format,
        public string $finishedAt,
        public ?int $rating,
    ) {}
}
