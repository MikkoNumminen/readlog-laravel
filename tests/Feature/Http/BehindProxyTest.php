<?php

use App\Models\User;

/*
| The app behind a reverse proxy: nginx locally, and a Cloudflare Tunnel in front
| of that when it is exposed for a demo. The tunnel terminates HTTPS and forwards
| plain HTTP with X-Forwarded-Proto and the public host. What has to hold:
|
|  - generated URLs use the forwarded scheme and host, not http://localhost;
|  - the session cookie carries the Secure flag when the outside request was HTTPS
|    (SESSION_SECURE_COOKIE is deliberately unset, so it follows the request);
|  - none of that happens unless the proxy is trusted, because otherwise any
|    client could claim to be HTTPS.
|
| .NET counterpart: there is no direct test in readlog-dotnet; its Program.cs
| configures ForwardedHeaders and relies on the framework. These pin the same
| behaviour here because it is configuration that only ever bites in production.
*/

$forwarded = [
    'HTTP_HOST' => 'demo.trycloudflare.com',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_HOST' => 'demo.trycloudflare.com',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
];

it('generates https links for the forwarded host when the proxy is trusted', function () use ($forwarded) {
    config()->set('trustedproxy.proxies', '*');
    User::factory()->create();

    $html = $this->get('/', $forwarded)->assertOk()->getContent();

    expect($html)->toContain('href="https://demo.trycloudflare.com/library"')
        ->and($html)->toContain('src="https://demo.trycloudflare.com/js/site.js"')
        ->and($html)->not->toContain('http://localhost');
});

it('marks the session cookie Secure when the outside request was https', function () use ($forwarded) {
    config()->set('trustedproxy.proxies', '*');

    $response = $this->get('/', $forwarded);

    $session = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($session)->not->toBeNull()
        ->and($session->isSecure())->toBeTrue();
});

it('ignores forwarded headers when no proxy is trusted', function () use ($forwarded) {
    config()->set('trustedproxy.proxies', null);

    $response = $this->get('/', $forwarded);
    $html = $response->getContent();

    // Neither the scheme claim nor the forwarded host is believed: links stay
    // http on the app's own host, and the cookie is not Secure. (The test client
    // sets HTTP_HOST from APP_URL, so "own host" is localhost here; behind nginx it
    // would be whatever the Host header said, which nothing filters.)
    expect($html)->toContain('href="http://localhost:8000/library"')
        ->and($html)->not->toContain('https://demo.trycloudflare.com');

    $session = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($session->isSecure())->toBeFalse();
});

it('reports the real client address through the proxy', function () use ($forwarded) {
    config()->set('trustedproxy.proxies', '*');

    $seen = null;
    Route::get('/whoami', function () use (&$seen) {
        $seen = request()->ip();

        return 'ok';
    });

    $this->get('/whoami', $forwarded)->assertOk();

    expect($seen)->toBe('203.0.113.9');
});

it('accepts a comma-separated proxy list, not only the wildcard', function () use ($forwarded) {
    // The test client's REMOTE_ADDR is 127.0.0.1, so trusting exactly that address
    // is the "cloudflared in front of php artisan serve on the host" case.
    config()->set('trustedproxy.proxies', '10.0.0.5,127.0.0.1');

    expect($this->get('/', $forwarded)->getContent())
        ->toContain('href="https://demo.trycloudflare.com/library"');
});
