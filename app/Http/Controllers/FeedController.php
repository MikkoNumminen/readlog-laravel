<?php

namespace App\Http\Controllers;

use App\Services\ReadLogService;
use Illuminate\View\View;

/**
 * The public "recently read" feed.
 *
 * .NET counterpart: Pages/Index.cshtml.cs. A Razor page model and a Laravel
 * controller action are close relatives: both are a class the framework resolves
 * from the container, with dependencies injected through the constructor. The
 * visible difference is that Razor Pages binds one class to one URL and exposes
 * the data as properties the view reads off `Model`, whereas a controller can
 * serve several routes and hands the view an explicit array.
 */
class FeedController extends Controller
{
    public function __construct(private readonly ReadLogService $readLog) {}

    public function index(): View
    {
        return view('feed', [
            'reads' => $this->readLog->getRecentPublicReads(),
        ]);
    }
}
