<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes generated URLs survive being served under a path on another host.
 *
 * The portfolio serves this app live at https://mikkonumminen.dev/readlog-laravel:
 * a Vercel function forwards the request to a Tailscale Funnel path mount, and the
 * mount strips its own prefix, so the app sees "/library" and would generate links
 * to "/library", which on the portfolio host escapes the mount and 404s. The
 * function announces where the visitor really is with two headers, and this
 * middleware makes every route(), asset() and redirect URL start there instead.
 *
 * The headers are validated, not trusted: a hostname shape and a single clean path
 * segment, nothing else. Anyone talking to the app directly can send them, and all
 * they change is where that sender's own links point, which is the same power
 * X-Forwarded-Host already grants behind TRUSTED_PROXIES. Nothing is cached, so
 * nobody can poison anyone else's page.
 *
 * .NET counterpart: UsePathBase() plus forwarded-headers middleware, which readlog-
 * dotnet never needed because Azure gave it the root of its own hostname.
 */
class PortalPrefix
{
    public const HOST_HEADER = 'X-Portal-Host';

    public const PREFIX_HEADER = 'X-Portal-Prefix';

    public function handle(Request $request, Closure $next): Response
    {
        $host = (string) $request->headers->get(self::HOST_HEADER, '');
        $prefix = (string) $request->headers->get(self::PREFIX_HEADER, '');

        if ($host !== '' && $prefix !== ''
            && preg_match('/^[a-z0-9][a-z0-9.-]{0,250}$/i', $host) === 1
            && preg_match('/^\/[a-z0-9-]{1,64}$/i', $prefix) === 1) {
            // Both calls: forceRootUrl alone keeps the incoming request's scheme,
            // and the last hop to this app is plain HTTP.
            URL::forceRootUrl('https://'.$host.$prefix);
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
