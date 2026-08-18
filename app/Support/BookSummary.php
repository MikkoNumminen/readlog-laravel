<?php

namespace App\Support;

/**
 * The catalogue fields shown for a book in a list.
 *
 * .NET counterpart: the `BookSummaryDto` record in Dtos/LibraryDtos.cs. A PHP
 * readonly class with promoted constructor properties is the closest thing to a
 * positional C# record; it lacks value equality and `with`, neither of which this
 * app uses.
 */
final readonly class BookSummary
{
    public function __construct(
        public string $title,
        public ?string $author,
        public ?string $coverUrl,
    ) {}
}
