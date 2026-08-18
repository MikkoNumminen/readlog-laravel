<?php

namespace App\Http\Controllers;

use App\Enums\Format;
use App\Exceptions\DuplicateReadEntryException;
use App\Http\Requests\LogBookRequest;
use App\Services\CurrentUser;
use App\Services\ReadLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Logging a finished book: search for it, pick it, fill in the details, save.
 *
 * .NET counterpart: Pages/Log.cshtml.cs. The source's single page model handles
 * both stages of the flow off one URL by looking at whether `olid` is present, and
 * that is kept, because the "change book" link and the browser back button both
 * depend on the two stages being addressable.
 *
 * Provider search (Open Library plus Google Books) arrives in phase 3. Until then
 * `$results` is always empty, which puts the page in the same state the .NET app
 * is in when no provider returns anything: the manual-add fallback.
 */
class LogController extends Controller
{
    public function __construct(
        private readonly ReadLogService $readLog,
        private readonly CurrentUser $currentUser,
    ) {}

    public function create(Request $request): View
    {
        $selectedId = $request->query('olid');

        if (is_string($selectedId) && $selectedId !== '') {
            // A book has been chosen: show the log form, prefilled from the query.
            return view('log', [
                'hasSelection' => true,
                'selection' => [
                    'open_library_id' => $selectedId,
                    'title' => (string) $request->query('sel_title', ''),
                    'author' => $request->query('sel_author'),
                    'cover_url' => $request->query('cover'),
                    'page_count' => $this->intOrNull($request->query('pages')),
                    'first_publish_year' => $this->intOrNull($request->query('year')),
                    'format' => Format::Book,
                    'finished_at' => Carbon::now('UTC')->toDateString(),
                    'rating' => null,
                ],
            ]);
        }

        $title = trim((string) $request->query('title', ''));
        $author = trim((string) $request->query('author', ''));
        $searchTerm = trim($title.' '.$author);

        return view('log', [
            'hasSelection' => false,
            'title' => $title,
            'author' => $author,
            'searched' => $searchTerm !== '',
            'results' => collect(),

            // A manual-add fallback is offered after every search, not only after an
            // empty one: the providers return irrelevant-but-nonzero hits for niche
            // titles, which would otherwise hide the option behind a result list.
            'manualId' => $searchTerm === '' ? null : 'manual:'.Str::random(32),
        ]);
    }

    public function store(LogBookRequest $request): RedirectResponse
    {
        try {
            $this->readLog->logBook($this->currentUser->id(), $request->toData());
        } catch (DuplicateReadEntryException) {
            return back()
                ->withInput()
                ->withErrors(['form' => "You've already logged this book with that finished date."]);
        }

        return redirect()->route('library.index')->with('notice', 'Book added to your library.');
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
