<?php

namespace App\Providers;

use App\Services\CurrentUser;
use Illuminate\Support\Facades\View;
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
        // .NET counterpart: the ambient `User` a Razor view can read without anyone
        // passing it in. Blade has no ambient principal, so the reader switcher gets
        // its data from a view composer rather than reaching into the container from
        // inside the template.
        View::composer('partials.demo-user', function ($view) {
            $currentUser = $this->app->make(CurrentUser::class);

            $view->with([
                'demoReaders' => $currentUser->selectable(),
                'demoReader' => $currentUser->get(),
            ]);
        });
    }
}
