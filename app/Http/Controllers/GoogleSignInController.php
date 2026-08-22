<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleSignInException;
use App\Models\User;
use App\Services\Auth\GoogleOAuth;
use App\Support\GoogleProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Signing in with Google, and signing out again.
 *
 * .NET counterpart: Pages/SignIn.cshtml.cs plus the ExternalLogin handlers that
 * ASP.NET Core Identity generates. Identity does the account lookup and creation
 * for you; here it is the ten lines of `userFor()` below, which is the whole of
 * what Identity's external-login store was doing for this app.
 */
class GoogleSignInController extends Controller
{
    public function __construct(private readonly GoogleOAuth $google) {}

    /** The page with the button, and the place every failure comes back to. */
    public function show(): View
    {
        return view('signin', ['enabled' => $this->google->enabled()]);
    }

    /** Step one: hand the browser to Google, remembering why. */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->google->enabled()) {
            return redirect()->route('signin')
                ->withErrors(['form' => 'Signing in is not configured on this instance.']);
        }

        $state = $this->google->freshState();
        $request->session()->put(GoogleOAuth::STATE_SESSION_KEY, $state);

        return redirect()->away($this->google->authorizeUrl($state, $this->callbackUrl()));
    }

    /** Step two: Google hands the browser back. Nothing here is trusted yet. */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->get(GoogleOAuth::STATE_SESSION_KEY);
        $state = $request->query('state');

        // The state is the only thing tying this callback to the browser that
        // started the sign-in. A missing or wrong one means this request was not
        // started here, so it is refused before the code is ever spent.
        //
        // Read, not pulled: this is a GET with no CSRF token, so anyone who can
        // make the browser follow a link can reach it with a wrong state. Pulling
        // first let a stranger's link cancel a sign-in the reader had genuinely
        // started, and the reader saw "expired" for no reason. The state is
        // forgotten below, once it has matched, so it is still single use.
        if (! is_string($expected) || $expected === '' || ! is_string($state) || ! hash_equals($expected, $state)) {
            return $this->refuse('That sign-in link has expired. Please try again.');
        }

        $request->session()->forget(GoogleOAuth::STATE_SESSION_KEY);

        if ($request->query('error') !== null || ! is_string($code = $request->query('code')) || $code === '') {
            return $this->refuse('Google did not complete the sign-in.');
        }

        try {
            $profile = $this->google->profileFor($code, $this->callbackUrl());
            $user = $this->userFor($profile);
        } catch (GoogleSignInException $e) {
            // The reason is for the operator; the reader gets one plain sentence,
            // because the detail can name the account and the failure mode.
            Log::info('Google sign-in failed.', ['reason' => $e->getMessage()]);

            return $this->refuse($e->getMessage());
        }

        // No remember cookie. Laravel's is a five year credential, which is a lot
        // to hand a browser nobody asked about on a machine that may be shared.
        // The .NET original's fourteen day sliding cookie is the parity target,
        // and config/session.php carries that instead.
        Auth::login($user);

        // A new session id after a privilege change, so a session fixed before
        // sign-in is worthless afterwards.
        $request->session()->regenerate();

        return redirect()->to($this->intendedPath($request));
    }

    /**
     * Where to land after signing in, as a path this app will rebuild itself.
     *
     * Laravel stores the intended url as the absolute one the request carried,
     * and that url is the funnel's, not the portal's: `url.intended` is written
     * from the raw request, before PortalPrefix has anything to say about it.
     * Redirecting to it verbatim sent a visitor who signed in at
     * mikkonumminen.dev/readlog-laravel to the machine's own hostname, which is
     * both broken and a disclosure. Reducing it to path and query and handing it
     * back to url() puts the portal root on, and drops any host a caller managed
     * to get in there.
     */
    private function intendedPath(Request $request): string
    {
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return route('library.index');
        }

        $parts = parse_url($intended);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '';
        $query = is_array($parts) && is_string($parts['query'] ?? null) ? '?'.$parts['query'] : '';

        if ($path === '' || ! str_starts_with($path, '/')) {
            return route('library.index');
        }

        return url($path.$query);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('feed');
    }

    /**
     * The account this profile belongs to, created on first sign-in.
     *
     * Three cases in order: an account already keyed to this Google id, an account
     * with the same verified address that has never signed in with Google (the
     * seeded readers, and any local account a later TODO adds), and a new person.
     * Linking by address is safe only because the provider told us it is verified,
     * which GoogleOAuth checks before this is ever reached.
     */
    private function userFor(GoogleProfile $profile): User
    {
        $user = User::query()->where('google_id', $profile->id)->first();

        if ($user === null) {
            $sameAddress = User::query()->where('email', $profile->email)->first();

            // The address is already an account's, under a different Google id.
            // Google hands out a new `sub` when a Workspace account is deleted and
            // recreated on the same address, so this is reachable without anybody
            // doing anything strange, and it used to be an uncaught unique
            // violation on users.email: a 500 on every attempt, forever. It is
            // refused rather than linked, because linking on an address alone
            // would let a recreated account inherit the old one's library.
            if ($sameAddress !== null
                && $sameAddress->google_id !== null
                && $sameAddress->google_id !== $profile->id) {
                throw new GoogleSignInException('That e-mail address already belongs to another account here.');
            }

            $user = $sameAddress;
        }

        if ($user === null) {
            // forceFill, not create: this model has no $fillable, so a mass
            // assignment would silently write nothing but the timestamps. The
            // seeder hit exactly that once and only worked because db:seed
            // unguards (MIGRATION.md, "where AI assistance was wrong", item 4).
            //
            // shares_publicly is left at its default of false: a stranger's
            // reading does not appear on a public feed because they signed in.
            $user = new User;

            try {
                $user->forceFill([
                    'name' => $profile->name,
                    'email' => $profile->email,
                    'google_id' => $profile->id,
                    'avatar_url' => $profile->avatarUrl,
                    'email_verified_at' => now(),
                ])->save();
            } catch (UniqueConstraintViolationException $e) {
                // Two first sign-ins for one account at once: the other request
                // created the row between the lookup above and this insert. Its
                // row is as good as ours, which is the same tolerance
                // ReadLogService::getOrCreateBook applies to the book catalogue.
                $winner = User::query()->where('google_id', $profile->id)->first();

                if ($winner === null) {
                    throw new GoogleSignInException('That account could not be created. Please try again.', 0, $e);
                }

                return $winner;
            }

            return $user;
        }

        // Keep the link and the picture current, but never overwrite a name the
        // person may have edited here.
        $user->forceFill([
            'google_id' => $profile->id,
            'avatar_url' => $profile->avatarUrl,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    /**
     * The callback address, built from the request so it survives the portal.
     *
     * Behind mikkonumminen.dev/readlog-laravel this is the portal URL, and on a
     * laptop it is localhost; PortalPrefix is what makes route() know the
     * difference. Google requires an exact match against a registered URI, so
     * both are registered. The same value has to be sent twice, once in the
     * consent URL and once in the token exchange, or Google refuses the code.
     */
    private function callbackUrl(): string
    {
        return route('signin.google.callback');
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('signin')->withErrors(['form' => $message]);
    }
}
