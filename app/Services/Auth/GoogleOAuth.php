<?php

namespace App\Services\Auth;

use App\Exceptions\GoogleSignInException;
use App\Support\GoogleProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The Google half of signing in: build the consent URL, then turn the code
 * Google sends back into a profile.
 *
 * .NET counterpart: `AddGoogle()` in Program.cs, which is one call because
 * ASP.NET Core ships the handler. Laravel's equivalent package is Socialite, and
 * it is deliberately not used here: this project has Guzzle 8 locked, Socialite
 * supports `^6.0|^7.0`, and the versions that would resolve pull a firebase/php-jwt
 * release under a published advisory. Downgrading the HTTP stack of the whole app
 * to add one sign-in button is the wrong trade, so the flow is written out. It is
 * the authorization code flow with nothing optional in it, and it looks like the
 * other three HTTP clients in this codebase on purpose. See decision 147.
 *
 * Two things this deliberately does NOT do:
 *
 * - It does not verify the `id_token` signature, because it never reads the
 *   `id_token`. The profile comes from a userinfo call made by this server, to
 *   Google, over TLS, with an access token this server obtained from Google's
 *   token endpoint using the client secret. Nothing in that chain passes through
 *   the browser, so there is no signature to check. That is also the reason
 *   Socialite needs a JWT library and this does not.
 * - It does not keep the access or refresh token. The app has no use for Google
 *   beyond identifying the person once; storing a credential it never spends
 *   would be a liability with no upside.
 */
class GoogleOAuth
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /** Where the state Google echoes back is kept between the two requests. */
    public const STATE_SESSION_KEY = 'google_oauth_state';

    /** Configured means both halves are present; half a credential is not a feature. */
    public function enabled(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        return (string) config('services.google.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.google.client_secret');
    }

    /** An unguessable value tying the callback to the browser that started this. */
    public function freshState(): string
    {
        return Str::random(40);
    }

    /**
     * Where to send the browser. `prompt=select_account` because a shared machine
     * showing a demo should not silently reuse whoever signed in last.
     */
    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Exchanges the code for a token, then reads the profile it unlocks.
     *
     * @throws GoogleSignInException for every failure, so the caller has one
     *                               thing to catch and one thing to say
     */
    public function profileFor(string $code, string $redirectUri): GoogleProfile
    {
        $token = $this->exchange($code, $redirectUri);
        $profile = $this->userinfo($token);

        if ($profile->id === '' || $profile->email === '') {
            throw new GoogleSignInException('Google did not return an account id and e-mail.');
        }

        // An unverified address is one somebody typed, not one they proved they
        // hold, and this app keys account linking on the address.
        if (! $profile->emailVerified) {
            throw new GoogleSignInException('That Google account has an unverified e-mail address.');
        }

        return $profile;
    }

    private function exchange(string $code, string $redirectUri): string
    {
        $response = $this->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new GoogleSignInException('Google did not return an access token.');
        }

        return $token;
    }

    private function userinfo(string $token): GoogleProfile
    {
        try {
            $response = Http::timeout($this->timeout())
                ->withToken($token)
                ->acceptJson()
                ->get(self::USERINFO_URL);
        } catch (ConnectionException $e) {
            throw new GoogleSignInException('Could not reach Google to read the profile.', 0, $e);
        }

        if ($response->failed()) {
            throw new GoogleSignInException("Google answered {$response->status()} when asked for the profile.");
        }

        return GoogleProfile::fromUserinfo($response->json() ?? []);
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function post(string $url, array $form): array
    {
        try {
            $response = Http::timeout($this->timeout())->asForm()->acceptJson()->post($url, $form);
        } catch (ConnectionException $e) {
            throw new GoogleSignInException('Could not reach Google to complete the sign-in.', 0, $e);
        }

        if ($response->failed()) {
            // The body of a failed token exchange echoes the request, and the
            // request carries the client secret. Only the status is safe to keep.
            throw new GoogleSignInException("Google answered {$response->status()} when exchanging the sign-in code.");
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function timeout(): int
    {
        return (int) config('services.google.timeout', 10);
    }
}
