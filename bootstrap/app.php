<?php

use App\Http\Middleware\RequireDemoUser;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // .NET counterpart: the middleware pipeline built in Program.cs with
        // app.UseAuthentication() / app.UseAuthorization(). Aliasing a class to a
        // short name lets routes opt in per group, which is where Laravel puts the
        // decision that .NET puts on the page class as [Authorize].
        $middleware->alias([
            'demo.user' => RequireDemoUser::class,
        ]);

        // .NET counterpart: the app.Use(...) block in Program.cs that stamps the
        // security headers on every response, registered before the endpoints so it
        // covers error responses too.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
