<?php

namespace App\Http\Controllers;

/**
 * The base every controller extends, and it is deliberately empty.
 *
 * .NET counterpart: PageModel, which Razor page models must inherit because it
 * carries Request, Response, ModelState, TempData and the redirect helpers.
 *
 * Laravel puts all of that in helpers and injected arguments instead, so nothing
 * needs to live here. Laravel 12 stopped generating this class with traits on it
 * for the same reason. It stays because `php artisan make:controller` still
 * extends it, and an empty base is cheaper than a generator override.
 *
 * Nothing shared between controllers belongs here. Shared behaviour goes in a
 * service (app/Services/) or in middleware; see ARCHITECTURE.md.
 */
abstract class Controller
{
    //
}
