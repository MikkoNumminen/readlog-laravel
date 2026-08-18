<?php

namespace App\Support;

use App\Enums\Format;

/**
 * Aggregate reading stats for one user.
 *
 * .NET counterpart: the `AccountStats` record in Dtos/LibraryDtos.cs.
 */
final readonly class AccountStats
{
    /**
     * @param  array<string, int>  $formats  keyed by Format value, missing when the count is zero
     */
    public function __construct(
        public int $totalBooks,
        public array $formats,
    ) {}

    public function countFor(Format $format): int
    {
        return $this->formats[$format->value] ?? 0;
    }
}
