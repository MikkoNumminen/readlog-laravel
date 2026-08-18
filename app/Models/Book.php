<?php

namespace App\Models;

use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A book in the shared catalogue: one row per real-world work, reused across every
 * user's read entries and keyed for idempotent find-or-create by open_library_id.
 *
 * .NET counterpart: Models/Book.cs.
 *
 * The paradigm difference worth naming: the .NET Book is a plain object that knows
 * nothing about persistence, and ApplicationDbContext maps it (data mapper). This
 * class IS the persistence gateway (Active Record), so Book::query(), $book->save()
 * and $book->readEntries all hang off the entity itself. There is no DbContext to
 * inject, and no change tracker deciding what to write.
 */
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    /**
     * The .NET entity has a single CreatedAt (Models/ICreatedAt.cs) and no
     * modified-at column, so Eloquent's updated_at half is switched off. Setting
     * this to null is how you tell Eloquent "this table has no updated_at";
     * leaving it on would make every save look for a column that is not there.
     */
    public const UPDATED_AT = null;

    /**
     * Mass-assignment allowlist. Closest .NET counterpart: guarding against
     * overposting, which ASP.NET Core does with a bound DTO (Dtos/LogBookRequest.cs)
     * rather than an attribute list on the entity.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'author',
        'cover_url',
        'open_library_id',
        'page_count',
        'first_publish_year',
    ];

    /**
     * .NET counterpart: the CLR property types on Book.cs. EF Core reads them from
     * the type system; Eloquent has no types to read, because every column arrives
     * from SQLite as a string, so the conversions are declared here.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_count' => 'integer',
            'first_publish_year' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * .NET counterpart: the ICollection<ReadEntry> navigation property.
     *
     * @return HasMany<ReadEntry, $this>
     */
    public function readEntries(): HasMany
    {
        return $this->hasMany(ReadEntry::class);
    }
}
