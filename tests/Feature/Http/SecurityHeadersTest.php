<?php

use App\Models\User;

/*
| Port of tests/ReadLog.Tests/Pages/SecurityHeadersTests.cs.
*/

it('stamps the security headers on every response', function (string $path) {
    $response = $this->get($path);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull();
})->with(['/', '/book?title=Dune', '/library', '/does-not-exist']);

it('sends a script-src with no unsafe-inline', function () {
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'self'")
        ->and($policy)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("base-uri 'self'");
});

it('allows https images so book covers load', function () {
    // Covers come from covers.openlibrary.org and books.google.com; the favicon is
    // an inline SVG, hence data:.
    expect($this->get('/')->headers->get('Content-Security-Policy'))
        ->toContain('img-src \'self\' https: data:');
});

it('binds the reader switcher without an inline event handler', function () {
    // The strict script-src above is only worth having if nothing on the page needs
    // 'unsafe-inline'. The switcher is the one control that would be tempted to.
    $user = User::factory()->create();

    $html = actingAsReader($user)->get('/library')->getContent();

    expect($html)->toContain('data-auto-submit')
        ->and($html)->not->toContain('onchange=');
});

it('names the app in every response, so the portal can tell it from a stranger on the same funnel port', function () {
    // The portal at mikkonumminen.dev/readlog-laravel shares a funnel port with
    // another project; without this header it cannot tell that project's 404
    // from one of ours. nginx adds the same header for responses PHP never sees.
    $this->get('/')->assertOk()->assertHeader('X-ReadLog-App', '1');
    $this->get('/definitely-not-a-route')->assertNotFound()->assertHeader('X-ReadLog-App', '1');
});
