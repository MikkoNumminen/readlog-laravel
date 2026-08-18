<?php

namespace App\Http\Controllers;

use App\Services\CurrentUser;
use App\Services\ReadLogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The user's own library, plus the "have I read this?" lookup over it.
 *
 * .NET counterpart: Pages/Library.cshtml.cs.
 */
class LibraryController extends Controller
{
    public function __construct(
        private readonly ReadLogService $readLog,
        private readonly CurrentUser $currentUser,
    ) {}

    public function index(Request $request): View
    {
        $userId = $this->currentUser->id();

        // Anything other than "list" is the grid, matching the source's
        // `view == "list" ? "list" : "grid"`.
        $view = $request->query('view') === 'list' ? 'list' : 'grid';

        $query = $request->query('q');
        $query = is_string($query) ? $query : null;

        // The source distinguishes "no q parameter at all" from "q=" (an empty
        // search box that was submitted): only the first hides the result panel.
        $searched = $query !== null && trim($query) !== '';

        return view('library', [
            'entries' => $this->readLog->getMyBooks($userId),
            'view' => $view,
            'query' => $query,
            'searched' => $searched,
            'searchResults' => $query === null
                ? collect()
                : $this->readLog->checkIfRead($userId, $query),
        ]);
    }
}
