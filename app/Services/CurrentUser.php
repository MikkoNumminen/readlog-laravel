<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

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
 * When real authentication lands, this class collapses to `auth()->id()` and the
 * switcher goes away. Nothing else in the app has to change, because no controller
 * or service reaches for the session directly.
 */
class CurrentUser
{
    public const SESSION_KEY = 'demo_user_id';

    public function __construct(private readonly Session $session) {}

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
        $id = $this->session->get(self::SESSION_KEY);

        if ($id !== null) {
            $user = User::find($id);

            if ($user !== null) {
                return $user;
            }

            // The session points at a user who is gone: drop the stale id rather
            // than showing an empty library forever.
            $this->session->forget(self::SESSION_KEY);
        }

        return User::query()->oldest('id')->first();
    }

    /**
     * The acting user's id.
     *
     * Callers are behind the require.demo.user middleware, so a user always exists
     * by the time this runs; a missing one is a programming error, which is the same
     * contract the .NET GetUserId() extension states.
     */
    public function id(): int
    {
        $user = $this->get();

        if ($user === null) {
            throw new \RuntimeException('No demo user exists. Run php artisan db:seed.');
        }

        return $user->id;
    }

    public function switchTo(User $user): void
    {
        $this->session->put(self::SESSION_KEY, $user->id);
    }

    /**
     * Everyone who can be switched to, for the demo picker.
     *
     * @return Collection<int, User>
     */
    public function selectable(): Collection
    {
        return User::query()->oldest('id')->get();
    }
}
