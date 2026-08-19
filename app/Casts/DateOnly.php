<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a calendar date as a plain `YYYY-MM-DD` string and reads it back as an
 * immutable Carbon pinned to midnight.
 *
 * .NET counterpart: `DateOnly` plus the EF Core value converter that persists it.
 * The .NET model can just say `DateOnly FinishedAt` and EF Core writes `2024-03-03`.
 *
 * PHP has no date-only type, and Laravel's built-in `date` cast is not a
 * substitute: it hands back a Carbon at midnight but still writes the connection's
 * full datetime format, so the column ends up holding `2024-03-03 00:00:00`. That
 * breaks the two things this app needs from the column:
 *
 *  - the unique (user_id, book_id, finished_at) index, and the find-or-create that
 *    leans on it, are matched against a `YYYY-MM-DD` value that comes off an
 *    HTML date input;
 *  - a database written by this app should still be readable by readlog-dotnet,
 *    which stores the bare date.
 *
 * CarbonImmutable rather than Carbon is deliberate: DateOnly is a value type in
 * .NET, and an immutable instance keeps `$entry->finished_at->addDay()` from
 * quietly mutating the model's attribute.
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonImmutable|DateTimeInterface|string>
 */
class DateOnly implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->toDateString()
            : CarbonImmutable::parse($value)->toDateString();
    }
}
