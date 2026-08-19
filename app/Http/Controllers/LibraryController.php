<?php

namespace App\Http\Controllers;

use App\Services\Ai\LibraryAsk;
use App\Services\Ai\OllamaClient;
use App\Services\CurrentUser;
use App\Services\ReadLogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The user's own library, plus the "have I read this?" lookup over it, plus the
 * "ask your library" question box that has no .NET counterpart.
 *
 * .NET counterpart: Pages/Library.cshtml.cs.
 */
class LibraryController extends Controller
{
    public function __construct(
        private readonly ReadLogService $readLog,
        private readonly CurrentUser $currentUser,
        private readonly LibraryAsk $ask,
        private readonly OllamaClient $ollama,
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

        // ?ask= is the AI question box. When Ollama cannot be used the question
        // is answered by the plain title search instead, and the page says so:
        // the reader always gets something, and never a broken page.
        $ask = $request->query('ask');
        $ask = is_string($ask) && trim($ask) !== '' ? trim($ask) : null;
        $askResult = $ask === null ? null : $this->ask->ask($userId, $ask);
        $askFallback = $askResult?->unavailable === true
            ? $this->readLog->checkIfRead($userId, $ask)
            : collect();

        return view('library', [
            'entries' => $this->readLog->getMyBooks($userId),
            'view' => $view,
            'query' => $query,
            'searched' => $searched,
            'searchResults' => $query === null
                ? collect()
                : $this->readLog->checkIfRead($userId, $query),
            'askEnabled' => $this->ollama->enabled(),
            'ask' => $ask,
            'askResult' => $askResult,
            'askFallback' => $askFallback,
        ]);
    }
}
