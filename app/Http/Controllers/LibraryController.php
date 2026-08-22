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
    private const ASK_MAX_LENGTH = 400;

    public function __construct(
        private readonly ReadLogService $readLog,
        private readonly CurrentUser $currentUser,
        private readonly LibraryAsk $ask,
        private readonly OllamaClient $ollama,
    ) {}

    public function index(Request $request): View
    {
        // Null when nobody has opted into being public and nobody is signed in,
        // which is every migrated-but-unseeded install. This page is public, so
        // that has to render an empty library rather than throw: CurrentUser::id()
        // is for the write routes, which the auth middleware guarantees a reader
        // for. Before this, /library answered 500 to every visitor on a fresh
        // database.
        $reader = $this->currentUser->get();

        // Anything other than "list" is the grid, matching the source's
        // `view == "list" ? "list" : "grid"`.
        $view = $request->query('view') === 'list' ? 'list' : 'grid';

        if ($reader === null) {
            return view('library', [
                'entries' => collect(),
                'view' => $view,
                'query' => null,
                'searched' => false,
                'searchResults' => collect(),
                'askEnabled' => false,
                'ask' => null,
                'askResult' => null,
                'askFallback' => collect(),
            ]);
        }

        $userId = $reader->id;

        $query = $request->query('q');
        $query = is_string($query) ? $query : null;

        // The source distinguishes "no q parameter at all" from "q=" (an empty
        // search box that was submitted): only the first hides the result panel.
        $searched = $query !== null && trim($query) !== '';

        // ?ask= is the AI question box. When Ollama cannot be used the question
        // is answered by the plain title search instead, and the page says so:
        // the reader always gets something, and never a broken page.
        // Capped: a question is a sentence, and everything after this goes to
        // the embedding model and into a prompt.
        $ask = $request->query('ask');
        $ask = is_string($ask) && trim($ask) !== '' ? mb_substr(trim($ask), 0, self::ASK_MAX_LENGTH) : null;
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
