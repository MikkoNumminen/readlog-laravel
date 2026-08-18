<?php

namespace App\Support;

use App\Enums\Format;

/**
 * Validated input for editing a read entry. The edit form posts the full current state.
 *
 * .NET counterpart: Dtos/UpdateReadEntryRequest.cs.
 */
final readonly class UpdateReadEntryData
{
    /**
     * @param  int|null  $rating  null clears the rating; 0 is a real value
     */
    public function __construct(
        public Format $format,
        public string $finishedAt,
        public ?int $rating,
    ) {}
}
