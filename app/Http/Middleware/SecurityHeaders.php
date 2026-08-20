<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security response headers on every response.
 *
 * .NET counterpart: the inline `app.Use(async (context, next) => ...)` block in
 * Program.cs. Same headers, same reasoning, with two values dropped because this
 * app does not have what they were protecting:
 *
 *  - form-action lists only 'self'. The source also allows accounts.google.com,
 *    because its sign-in form POSTs to /signin and is then 302'd to Google.
 *    There is no external login here.
 *  - There is no HSTS. That belongs to a TLS deployment, and this app is
 *    documented as running locally over plain HTTP with `php artisan serve`.
 *
 * The strict `script-src 'self'` is the reason public/js/site.js exists at all and
 * the reason the reader switcher binds its change handler there rather than with an
 * inline onchange attribute. An inline handler would need 'unsafe-inline', which
 * would undo most of what the policy is for.
 *
 * img-src has to allow https: because book covers come from covers.openlibrary.org
 * and books.google.com, and data: because the favicon is an inline SVG.
 */
class SecurityHeaders
{
    /** Names this app in a response, for the portal in front of it. */
    public const APP_MARKER = 'X-ReadLog-App';

    private const CONTENT_SECURITY_POLICY = "default-src 'self'; base-uri 'self'; object-src 'none'; "
        ."frame-ancestors 'none'; img-src 'self' https: data:; script-src 'self'; "
        ."style-src 'self' 'unsafe-inline'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // "This answer came from ReadLog." The portal that serves this app at
        // mikkonumminen.dev/readlog-laravel shares a funnel port with another
        // project, whose root handler answers 404 for our paths whenever our
        // mount is absent. Without a marker the portal cannot tell that 404
        // from one of ours, and would show a stranger's error instead of the
        // snapshot it falls back to. nginx adds the same header for the
        // responses it generates itself; see docker/nginx.conf.
        $response->headers->set(self::APP_MARKER, '1');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);

        return $response;
    }
}
