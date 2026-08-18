<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Which upstream addresses the app believes when they say "this request was
| really HTTPS, for this host, from this client" through the X-Forwarded-*
| headers. Laravel's TrustProxies middleware reads this key when no proxies are
| configured in code, so an env variable is all it takes.
|
| .NET counterpart: the ForwardedHeadersOptions block in Program.cs, which clears
| KnownNetworks and KnownProxies so every proxy is trusted, for the same reason
| this defaults to trusting the caller: the app only ever sits behind something
| that is the sole way in.
|
| Why it matters here: locally the app sits behind nginx (compose) and, when it
| is exposed for a demo, behind a Cloudflare Tunnel in front of that. The tunnel
| terminates HTTPS and forwards plain HTTP with X-Forwarded-Proto: https and the
| public hostname. Without trusting that, every generated URL says
| http://localhost and the session cookie is issued without the Secure flag on
| an https page, and browsers refuse it.
|
| Values:
|   "*"              trust whatever connected (nginx, or cloudflared on the host)
|   "127.0.0.1"      trust the local cloudflared in front of `php artisan serve`
|   "10.0.0.5,..."   a comma-separated list of proxy addresses or CIDR ranges
|   unset            trust nobody; forwarded headers are ignored
|
*/

return [

    'proxies' => env('TRUSTED_PROXIES'),

];
