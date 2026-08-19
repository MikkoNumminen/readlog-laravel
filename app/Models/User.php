<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * The application user.
 *
 * .NET counterpart: Models/ApplicationUser.cs, which extends IdentityUser with the
 * two profile fields ReadLog displays (Name, Image). Version 1 of this migration
 * does not port ASP.NET Core Identity, so this is Laravel's stock Authenticatable
 * plus the image column. See DECISIONS.md ("no authentication in version 1").
 *
 * The `password` column is left in place, unused, so that adding real
 * authentication later is a matter of wiring, not a schema change.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $image
 * @property Carbon|null $email_verified_at
 * @property-read Collection<int, ReadEntry> $readEntries
 */
#[Fillable(['name', 'email', 'password', 'image'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * .NET counterpart: the ICollection<ReadEntry> navigation property on
     * ApplicationUser.
     *
     * @return HasMany<ReadEntry, $this>
     */
    public function readEntries(): HasMany
    {
        return $this->hasMany(ReadEntry::class);
    }
}
