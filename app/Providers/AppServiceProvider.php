<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * .NET counterpart: the service-registration half of Program.cs, the run of
 * builder.Services.AddScoped / AddSingleton / AddHttpClient calls.
 *
 * Most of what Program.cs registers explicitly needs no line here at all. Laravel's
 * container resolves a concrete class with type-hinted constructor dependencies
 * without being told about it, so ReadLogService and every controller are
 * auto-wired. What is left is the handful of cases where the default is wrong.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to register, and that absence is the point: Laravel's container
        // auto-wires any concrete class whose constructor dependencies are
        // type-hinted, so ReadLogService, CurrentUser and every controller need no
        // line here. Program.cs has to name each one.
        //
        // CurrentUser was briefly bound as scoped, to save the repeated reader
        // lookups. See the comment on CurrentUser::get() for why that was wrong and
        // how the test suite caught it.
    }

    public function boot(): void
    {
        // "Ask your library" costs seconds of a local model per request and the
        // app can be on a public URL. Ten questions a minute per address is more
        // than a person asks; a page without ?ask= is not counted at all.
        RateLimiter::for('ask', function (Request $request) {
            $ask = $request->query('ask');

            return is_string($ask) && trim($ask) !== ''
                ? Limit::perMinute(10)->by($request->ip())
                : Limit::none();
        });
    }
}
