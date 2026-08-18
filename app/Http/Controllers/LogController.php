<?php

namespace App\Http\Controllers;

use App\Enums\Format;
use App\Exceptions\DuplicateReadEntryException;
use App\Http\Requests\LogBookRequest;
use App\Services\BookSearchService;
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
 */
class LogController extends Controller
{
    public function __construct(
        private readonly ReadLogService $readLog,
        private readonly CurrentUser $currentUser,
        private readonly BookSearchService $search,
    ) {}

    public function create(Request $request): View
    {
        $selectedId = $this->stringOrNull($request->query('olid'));

        if ($selectedId !== null) {
            // A book has been chosen: show the log form, prefilled from the query.
            return view('log', [
                'hasSelection' => true,
                'selection' => [
                    'open_library_id' => $selectedId,
                    'title' => $this->stringOrNull($request->query('sel_title')) ?? '',
                    'author' => $this->stringOrNull($request->query('sel_author')),
                    'cover_url' => $this->stringOrNull($request->query('cover')),
                    'page_count' => $this->intOrNull($request->query('pages')),
                    'first_publish_year' => $this->intOrNull($request->query('year')),
                    'format' => Format::Book,
                    'finished_at' => Carbon::now('UTC')->toDateString(),
                    'rating' => null,
                ],
            ]);
        }

        $title = trim($this->stringOrNull($request->query('title')) ?? '');
        $author = trim($this->stringOrNull($request->query('author')) ?? '');
        $searchTerm = trim($title.' '.$author);

        return view('log', [
            'hasSelection' => false,
            'title' => $title,
            'author' => $author,
            'searched' => $searchTerm !== '',
            'results' => $searchTerm === '' ? collect() : $this->search->search($searchTerm),

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

    /**
     * Query-string values are attacker-controlled in both shape and type: ?title[]=x
     * arrives as an array, and casting that to string is a warning plus the literal
     * word "Array". Anything that is not a non-empty string is treated as absent.
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
