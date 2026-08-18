<?php

namespace App\Http\Middleware;

use App\Services\CurrentUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the pages that only make sense on behalf of a reader.
 *
 * .NET counterpart: the `[Authorize]` attribute on LogModel, LibraryModel,
 * EditModel and AccountModel, which the authentication middleware turns into a
 * redirect to /signin.
 *
 * The mapping is closer than it looks. ASP.NET Core middleware and Laravel
 * middleware are the same shape: a pipeline of components, each handed the request
 * and a delegate to the next one, each free to short-circuit. `$next($request)` is
 * `await next(context)`. The difference is where the decision is declared: .NET
 * puts it on the page class as an attribute and resolves it through an
 * authorisation filter, Laravel puts it on the route.
 *
 * Version 1 has no login page to redirect to, so an unseeded database sends the
 * visitor home with an explanation instead.
 */
class RequireDemoUser
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentUser->get() === null) {
            return redirect()
                ->route('feed')
                ->with('notice', 'No reader exists yet. Run "php artisan db:seed" to create the demo library.');
        }

        return $next($request);
    }
}
