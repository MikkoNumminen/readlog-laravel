<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The embedding vector for one reading entry. See the migration for why it is a
 * JSON text column and not a vector type.
 *
 * @property int $id
 * @property int $read_entry_id
 * @property string $model
 * @property int $dimensions
 * @property string $content_hash
 * @property list<float> $vector
 * @property Carbon $created_at
 * @property-read ReadEntry $entry
 *
 * .NET counterpart: none. There is no embedding table in the source schema.
 */
class ReadEntryEmbedding extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['read_entry_id', 'model', 'dimensions', 'content_hash', 'vector'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Stored as a JSON array. Read back as floats even where json_encode wrote
     * a whole number without a decimal point, so callers can rely on the type.
     *
     * @return Attribute<list<float>, string>
     */
    protected function vector(): Attribute
    {
        return Attribute::make(
            get: fn (?string $json) => array_map(floatval(...), is_array($decoded = json_decode((string) $json, true)) ? array_values($decoded) : []),
            set: fn (array $vector) => json_encode(array_values($vector)),
        );
    }

    /** @return BelongsTo<ReadEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(ReadEntry::class, 'read_entry_id');
    }
}
