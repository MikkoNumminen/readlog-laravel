<?php

namespace App\Http\Controllers;

use App\Services\BookDescriptionSanitizer;
use App\Services\BookDetailsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The book detail page reached from the feed.
 *
 * .NET counterpart: Pages/Book.cshtml.cs. The page identifies a book by title and
 * author in the query string rather than by id, because the feed links to a
 * catalogue entry the reader may not have logged, and the details come from Google
 * Books rather than from this app's database.
 *
 * Two things are sanitised here rather than in the view, so that the view has
 * nothing left to decide:
 *
 *  - the description HTML, which is third-party content rendered unescaped;
 *  - the info link, which is only passed on when it is a real http(s) URL, so a
 *    hostile provider response cannot put a javascript: or data: scheme into an
 *    href.
 */
class BookController extends Controller
{
    public function __construct(
        private readonly BookDetailsService $bookDetails,
        private readonly BookDescriptionSanitizer $sanitizer,
    ) {}

    public function show(Request $request): View
    {
        $titleParam = $request->query('title');
        $title = is_string($titleParam) ? trim($titleParam) : '';

        abort_if($title === '', 404);

        $authorParam = $request->query('author');
        $author = is_string($authorParam) && trim($authorParam) !== '' ? trim($authorParam) : null;

        $cover = $request->query('cover');

        $details = $this->bookDetails->getDetails($title, $author);

        $description = $details?->description;

        return view('book', [
            'title' => $title,
            'fallbackCoverUrl' => is_string($cover) ? $cover : null,
            'details' => $details,
            'safeDescriptionHtml' => $description === null || $description === ''
                ? null
                : $this->sanitizer->sanitize($description),
            'safeInfoLink' => $this->safeLink($details?->infoLink),
        ]);
    }

    /**
     * .NET counterpart: the Uri.TryCreate plus scheme check on BookModel.
     */
    private function safeLink(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
