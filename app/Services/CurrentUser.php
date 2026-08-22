<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves who the app is acting as.
 *
 * .NET counterpart: `User.GetUserId()` (Auth/ClaimsPrincipalExtensions.cs) reading
 * the NameIdentifier claim off the authenticated principal.
 *
 * There is no authentication in version 1 (see DECISIONS.md), so this is a demo
 * stand-in, not a port: the acting user is held in the session and can be switched
 * from the navigation bar. What it preserves is the part that matters to the domain,
 * that every service call is made on behalf of a specific user id, so the ownership
 * rules ported from ReadLogService are real and testable rather than assumed.
 *
 * Real authentication landed in the Google sign-in PR, and this class did most of
 * what it promised: the switcher is gone and a signed-in reader is `auth()->user()`.
 * It still exists because the app has a second kind of visitor the .NET original
 * never had. The public URL serves the app to anyone, and a signed-out visitor is
 * shown the showcase reader's library rather than a login wall, so "the acting
 * reader" is still a question with two answers. Every write route is behind the
 * `auth` middleware, so nothing a guest can reach can change anything.
 */
class CurrentUser
{
    /**
     * The acting user, or null when the database has no users at all
     * (a migrated but unseeded install).
     *
     * Deliberately not memoised, and the class is deliberately not bound as a
     * singleton or as scoped. Both were tried, to save the three or four lookups a
     * request makes (middleware, controller, view composer), and both are wrong for
     * the same reason: this object holds a Session, and a container binding that
     * outlives one request hands the next request the previous request's session.
     * Under `php artisan serve` the process is torn down between requests so the
     * bug is invisible, but the test suite handles many requests in one process and
     * caught it immediately, with one reader's library showing up for another.
     *
     * The lookups it saves are single indexed primary-key reads. Not worth it.
     */
    public function get(): ?User
    {
        $user = Auth::user();

        if ($user instanceof User) {
            return $user;
        }

        return $this->showcase();
    }

    /**
     * The reader a signed-out visitor is shown: the oldest account that has opted
     * into being public.
     *
     * Never just "the oldest account", which is what this used to return. Anyone
     * can sign in now, and on a fresh machine the first person through the door
     * could be a stranger; handing their library to every visitor because they
     * happened to register first is exactly the accident `shares_publicly` exists
     * to prevent. Returns null when nobody has opted in, which the views handle
     * as an empty library rather than an error.
     */
    public function showcase(): ?User
    {
        return User::query()->where('shares_publicly', true)->oldest('id')->first();
    }

    /** Is the acting reader allowed to change anything? */
    public function canWrite(): bool
    {
        return Auth::check();
    }

    /**
     * The acting user's id.
     *
     * Callers are behind the auth middleware, so a user always exists
     * by the time this runs; a missing one is a programming error, which is the same
     * contract the .NET GetUserId() extension states.
     */
    public function id(): int
    {
        $user = $this->get();

        if ($user === null) {
            throw new \RuntimeException('No acting reader. Write routes are behind the auth middleware, so this is a programming error.');
        }

        return $user->id;
    }
}
