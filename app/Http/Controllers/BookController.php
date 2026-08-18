<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The book detail page reached from the feed.
 *
 * .NET counterpart: Pages/Book.cshtml.cs. The page identifies a book by title and
 * author in the query string rather than by id, because the feed links to a
 * catalogue entry that the reader may not have logged, and the details themselves
 * come from Google Books rather than from this app's database.
 *
 * Phase 2 renders the shell only, so `$details` is always null and the page shows
 * "No details available for this book." That is not a placeholder: it is exactly
 * what readlog-dotnet renders when no Google Books API key is configured. Phase 3
 * fills in the lookup.
 */
class BookController extends Controller
{
    public function show(Request $request): View
    {
        $title = trim((string) $request->query('title', ''));

        abort_if($title === '', 404);

        return view('book', [
            'title' => $title,
            'fallbackCoverUrl' => $request->query('cover'),
            'details' => null,
            'safeDescriptionHtml' => null,
            'safeInfoLink' => null,
        ]);
    }
}
