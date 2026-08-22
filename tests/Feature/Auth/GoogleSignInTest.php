<?php

use App\Models\ReadEntry;
use App\Models\User;
use App\Services\Auth\GoogleOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Signing in with Google, with Google faked.
|
| The flow is written out rather than taken from Socialite (decision 145), so
| the parts a package would have owned are the parts most worth pinning: the
| state check, the refusal of an unverified address, account linking, and that
| a failure ends on the sign-in page with one sentence rather than a stack trace.
*/

beforeEach(function () {
    config()->set('services.google.client_id', 'client-id.apps.googleusercontent.com');
    config()->set('services.google.client_secret', 'client-secret');
});

/** @param array<string, mixed> $profile */
function fakeGoogle(array $profile = [], int $tokenStatus = 200): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-abc'], $tokenStatus),
        'www.googleapis.com/oauth2/v3/userinfo' => Http::response(array_merge([
            'sub' => '1234567890',
            'email' => 'reader@example.com',
            'email_verified' => true,
            'name' => 'A Reader',
            'picture' => 'https://lh3.googleusercontent.com/a/abc',
        ], $profile)),
    ]);
}

/** Walks the browser's half: start the flow, keep the state, come back with it. */
function callbackWithValidState(string $code = 'auth-code'): TestResponse
{
    test()->get('/signin/google')->assertRedirect();
    $state = session(GoogleOAuth::STATE_SESSION_KEY);

    return test()->get('/signin/google/callback?code='.$code.'&state='.$state);
}

it('sends the browser to Google with a state, a scope and an exact callback', function () {
    $response = $this->get('/signin/google')->assertRedirect();

    $url = $response->headers->get('Location');
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($query['client_id'])->toBe('client-id.apps.googleusercontent.com')
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('openid email profile')
        ->and($query['redirect_uri'])->toBe(route('signin.google.callback'))
        ->and($query['state'])->toBe(session(GoogleOAuth::STATE_SESSION_KEY))
        ->and($query['state'])->not->toBeEmpty();
});

it('creates an account on the first sign-in, private by default', function () {
    fakeGoogle();

    callbackWithValidState()->assertRedirect(route('library.index'));

    $user = User::query()->where('email', 'reader@example.com')->sole();
    expect($user->google_id)->toBe('1234567890')
        ->and($user->name)->toBe('A Reader')
        ->and($user->avatar_url)->toBe('https://lh3.googleusercontent.com/a/abc')
        ->and($user->shares_publicly)->toBeFalse()   // a stranger's reading is not published
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(auth()->id())->toBe($user->id);
});

it('signs the same person back in without making a second account', function () {
    fakeGoogle();
    callbackWithValidState();
    $first = auth()->id();
    auth()->logout();

    fakeGoogle(['name' => 'A Renamed Reader']);
    callbackWithValidState();

    expect(User::query()->count())->toBe(1)
        ->and(auth()->id())->toBe($first)
        // The name is theirs to change here, so Google does not overwrite it.
        ->and(User::query()->sole()->name)->toBe('A Reader');
});

it('links an existing account with the same verified address instead of duplicating it', function () {
    $seeded = User::factory()->sharesPublicly()->create(['email' => 'reader@example.com', 'name' => 'Seeded Reader']);
    ReadEntry::factory()->for($seeded)->create();
    fakeGoogle();

    callbackWithValidState();

    expect(User::query()->count())->toBe(1)
        ->and(auth()->id())->toBe($seeded->id)
        ->and($seeded->fresh()->google_id)->toBe('1234567890')
        // Linking must not quietly change what that account already shared.
        ->and($seeded->fresh()->shares_publicly)->toBeTrue();
});

it('refuses a callback whose state does not match the one it issued', function () {
    fakeGoogle();

    $this->get('/signin/google')->assertRedirect();
    $this->get('/signin/google/callback?code=auth-code&state=not-the-state')
        ->assertRedirect(route('signin'))
        ->assertSessionHasErrors('form');

    expect(User::query()->count())->toBe(0)
        ->and(auth()->check())->toBeFalse();
    // The code is never spent when the state is wrong.
    Http::assertNothingSent();
});

it('refuses a callback with no state in the session at all', function () {
    fakeGoogle();

    $this->get('/signin/google/callback?code=auth-code&state=anything')
        ->assertRedirect(route('signin'))
        ->assertSessionHasErrors('form');

    expect(auth()->check())->toBeFalse();
    Http::assertNothingSent();
});

it('refuses an account whose e-mail Google has not verified', function () {
    fakeGoogle(['email_verified' => false]);

    callbackWithValidState()->assertRedirect(route('signin'))->assertSessionHasErrors('form');

    expect(User::query()->count())->toBe(0)->and(auth()->check())->toBeFalse();
});

it('ends on the sign-in page when Google refuses or cannot be reached', function () {
    fakeGoogle(tokenStatus: 401);
    callbackWithValidState()->assertRedirect(route('signin'))->assertSessionHasErrors('form');
    expect(auth()->check())->toBeFalse();

    Http::fake(['oauth2.googleapis.com/token' => fn () => throw new ConnectionException('offline')]);
    callbackWithValidState()->assertRedirect(route('signin'))->assertSessionHasErrors('form');
    expect(auth()->check())->toBeFalse();
});

it('says nothing about the client secret when the exchange fails', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client', 'secret' => 'client-secret'], 401)]);

    $this->get('/signin/google')->assertRedirect();
    $state = session(GoogleOAuth::STATE_SESSION_KEY);
    $this->followingRedirects()
        ->get('/signin/google/callback?code=auth-code&state='.$state)
        ->assertOk()
        ->assertDontSee('client-secret');
});

it('carries the visitor back to the page that asked them to sign in', function () {
    fakeGoogle();

    $this->get('/log')->assertRedirect(route('signin'));   // stores the intended url
    callbackWithValidState()->assertRedirect('/log');
});

it('signs out, forgets the session and leaves the reader anonymous', function () {
    fakeGoogle();
    callbackWithValidState();
    expect(auth()->check())->toBeTrue();

    $this->post('/signout')->assertRedirect(route('feed'));

    expect(auth()->check())->toBeFalse();
    $this->get('/log')->assertRedirect(route('signin'));
});

it('offers no way in when Google is not configured, and says so', function () {
    config()->set('services.google.client_id', '');
    config()->set('services.google.client_secret', '');
    Http::fake();

    $this->get('/signin')->assertOk()->assertSee('not configured')->assertDontSee('Continue with Google');
    $this->get('/signin/google')->assertRedirect(route('signin'))->assertSessionHasErrors('form');
    Http::assertNothingSent();
});
