<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Format;
use Carbon\CarbonImmutable;
use Database\Factories\ReadEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A single "I finished this book" record, owned by one user.
 *
 * .NET counterpart: Models/ReadEntry.cs.
 *
 * The @property lines are the attribute types as the casts below produce them.
 * Eloquent will not tell a static analyser what $entry->format is; the .NET
 * entity carries the same information in its property declarations.
 *
 * @property int $id
 * @property int $user_id
 * @property int $book_id
 * @property Format $format
 * @property-read CarbonImmutable $finished_at
 * @property-write CarbonImmutable|\DateTimeInterface|string $finished_at
 * @property int|null $rating
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Book $book
 * @property-read ReadEntryEmbedding|null $embedding
 */
class ReadEntry extends Model
{
    /** @use HasFactory<ReadEntryFactory> */
    use HasFactory;

    /** No updated_at column, for the same reason as Book. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'book_id',
        'format',
        'finished_at',
        'rating',
    ];

    /**
     * `format` casts to the backed enum, which is the Eloquent counterpart of EF
     * Core's `HasConversion<string>()`. `finished_at` uses the hand-written
     * App\Casts\DateOnly, because Laravel's built-in `date` cast reads back as a
     * date but still writes a full datetime into the column; see that class for
     * why that breaks this schema.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'finished_at' => DateOnly::class,
            'rating' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * The entry's embedding for the "ask your library" search, if Ollama has
     * been reachable since the entry last changed. Absent is a normal state.
     *
     * @return HasOne<ReadEntryEmbedding, $this>
     */
    public function embedding(): HasOne
    {
        return $this->hasOne(ReadEntryEmbedding::class);
    }
}
