<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The parts of a Google profile this app keeps: who they are, what to call them,
 * and a picture for the account page.
 *
 * .NET counterpart: the claims ASP.NET Core Identity puts on the principal after
 * an external login, plus the `picture` claim Program.cs adds by hand.
 */
final readonly class GoogleProfile
{
    public function __construct(
        public string $id,
        public string $email,
        public bool $emailVerified,
        public string $name,
        public ?string $avatarUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $body  a Google userinfo response
     */
    public static function fromUserinfo(array $body): self
    {
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $avatar = is_string($body['picture'] ?? null) ? $body['picture'] : null;

        return new self(
            id: is_string($body['sub'] ?? null) ? $body['sub'] : '',
            email: $email,
            // Google sends this as a real boolean, and older docs as the string
            // "true". Anything else is treated as unverified, which fails closed.
            emailVerified: ($body['email_verified'] ?? false) === true || ($body['email_verified'] ?? null) === 'true',
            // Falling back to the address's local part keeps the display name from
            // ever being empty, which the account page and the feed both assume.
            name: $name !== '' ? $name : Str::before($email, '@'),
            avatarUrl: $avatar !== null && str_starts_with($avatar, 'https://') ? $avatar : null,
        );
    }
}
