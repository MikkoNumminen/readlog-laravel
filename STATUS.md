# Status

Where this project stands, what each pull request contains, and what was not done.

Last updated: 2026-08-18.

## Summary

The migration of [readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet)
to Laravel 13 is **complete for version 1 scope**. Every feature in that scope is
implemented and tested, the app runs from a clean clone with four commands, and
the test suite is green.

Version 1 scope, from the brief, was four things: books CRUD matching the .NET
model, the reading-entry log, the search the source has, and the multi-source
Open Library plus Google Books lookup with its merge logic. All four are done.
Authentication was not in scope and is not implemented; see below.

```
196 passing tests, 3 skipped (live API), 503 assertions
34 PHP files in app/, 13 Blade views, 18 test files
```

## What each pull request contains

### [PR 1: Laravel skeleton and the ReadLog domain](https://github.com/MikkoNumminen/readlog-laravel/pull/1)

Merged. 7 commits.

The stock Laravel 13 install committed unmodified first, so every later diff reads
against a known baseline. Pest replaces PHPUnit as the runner. The Vite front end
is removed, because the brief asks for an app that runs with `php artisan serve`
and Vite would put `npm install && npm run build` between a clone and a rendered
page.

Migrations for `books` and `read_entries` matching the .NET schema, the `Book`,
`ReadEntry` and `User` models, the `Format` backed enum, and `App\Casts\DateOnly`.
Seed data of two readers, twelve real books with genuine Open Library work keys,
and fourteen entries. 35 tests. CI added.

Self-review found three things, all fixed before the PR opened: a seeder that
depended on `artisan db:seed` running models unguarded, an unused import, and an
`.env.example` still advertising AWS, Redis, Memcached and Vite.

### [PR 2: books, reading entries, search and the Blade UI](https://github.com/MikkoNumminen/readlog-laravel/pull/2)

Merged. 5 commits.

`ReadLogService`, a case-for-case port of the .NET domain service. Six
controllers, two form requests, the `[Authorize]` counterpart as middleware, the
security-header middleware, and the whole Blade UI with a hand-written stylesheet.
After this PR the app is usable end to end. Tests went from 35 to 124.

Self-review found five things. The one worth reading is an optimisation that was
put in and taken out: binding `CurrentUser` as `scoped` and memoising the resolved
reader introduced a correctness bug, because the class holds a `Session` and a
binding that outlives a request hands the next request the previous session. Under
`artisan serve` each request is a fresh process and the bug is invisible; the test
suite handles many requests in one process and failed immediately.

### [PR 3: Open Library and Google Books lookup with merge](https://github.com/MikkoNumminen/readlog-laravel/pull/3)

Merged. 5 commits.

The two provider clients, the concurrent fan-out with `Http::pool`, the
de-duplication and merge, the cached detail lookup, and the HTML sanitiser for
third-party description markup. 75 new tests, including the conflicting-metadata
merge case the brief asks for, and three live-API tests behind a config flag.

Self-review found four things, one of them a credential leak: Guzzle puts the full
request URL into a connection-failure message and Google Books only accepts its
key in the query string, so a DNS blip wrote the API key into `storage/logs`. The
.NET original does not have this problem, because `HttpRequestException` does not
carry the URI, so there was nothing in the source to warn against it.

### [PR 4: documentation](https://github.com/MikkoNumminen/readlog-laravel/pull/4)

MIGRATION.md, README.md, TODO.md and this file.

## What was deliberately not done

### No authentication

readlog-dotnet has ASP.NET Core Identity with local accounts, lockout, and an
optional Google login. None of it is here.

Version 1 scope was four features and the brief said "this only". Porting Identity
properly is its own phase, and the shortest Laravel route to it, Laravel Breeze,
would reintroduce npm and a build step, which the brief rules out.

What is **not** skipped is per-user ownership. Read entries belong to a user, the
library is per-user, the account stats are per-user, and another reader's entry
answers 404 rather than 403. All of that is ported and tested.
`app/Services/CurrentUser.php` is a session-backed demo stand-in that lets you
switch between the seeded readers from the navigation bar, so the ownership rules
are visible rather than theoretical. It collapses to `auth()->id()` when real auth
lands, and no controller or service reaches for the session itself, so nothing
else has to change. See TODO.md.

One consequence worth stating plainly: with no authentication, nothing in this app
is private. The reader switcher lists every seeded reader's name to anonymous
visitors, and any visitor can act as any reader. That is what a demo without login
means, and it is why this is not deployed anywhere.

### The database-level rating constraint

The .NET schema declares `CK_ReadEntry_Rating`, so the database refuses a rating
outside 0 to 5. Laravel's schema builder has no check-constraint API and SQLite
cannot add one to an existing table, so the bound lives in request validation
only. A raw SQL insert can store `rating = 9`.

This is a real loss of a guarantee, so
`tests/Feature/Database/SchemaConstraintsTest.php` contains a test asserting the
gap rather than a comment hiding it. It should fail and be rewritten when the
constraint is restored.

### Editing an entry onto an occupied date returns a 500

The unique `(user_id, book_id, finished_at)` index rejects the update and nothing
catches the violation. readlog-dotnet has exactly the same hole in
`Pages/Library/Edit.cshtml.cs`, and the brief said to match behaviour rather than
improve on it, so it was ported as found. Pinned by a test in
`tests/Feature/Http/EntryEditTest.php`. The fix is a five-line copy of the pattern
`ReadLogService::logBook()` already uses; it is in TODO.md.

### Two flaws in the merge logic, carried over on purpose

`BookSearchService::deduplicate()` resolves conflicts between providers by
richness, not correctness. When Open Library and Google Books disagree about the
same book, whichever record has more non-null cover and page-count fields wins
whole, and everything the other knew is discarded, including a more plausible
publication year. There is a test that says so in as many words.

`normalise()` strips everything outside `[a-z0-9]`, so two unrelated books with
Cyrillic or CJK titles both key on the empty string and one is thrown away. The
.NET `[GeneratedRegex("[^a-z0-9]")]` has the identical hole.

Both are in readlog-dotnet and both are pinned by tests asserting the current
behaviour, so a future fix has to change a test on purpose rather than by accident.

### No static analyser

readlog-dotnet sets `WarningsAsErrors=nullable`, so a possible null dereference
does not compile. Nothing equivalent runs here before the code does. PHPStan or
Psalm would recover most of it and CI already has a place for it next to
`pint --test`. This is the single largest thing lost in the move and it is in
TODO.md.

### Deployment, containers and health checks

readlog-dotnet has a Dockerfile, an Azure App Service deployment workflow,
forwarded-headers handling, HSTS, and persisted data-protection keys. All of it is
deployment concern for an app that is documented as running locally, and the brief
rules out anything needing hosting. Laravel ships `/up`, which covers the health
check.

### AI features

Nothing AI-assisted is implemented. The natural-language library search, the
recommendation idea and the LLM-assisted metadata merge are all recorded in
TODO.md with the reasoning for parking each. Building any of them in version 1
would have made the comparison in MIGRATION.md dishonest, because readlog-dotnet
has no counterpart to compare against.

## Known rough edges

- The demo reader switcher submits on `change`, so it needs JavaScript. There is a
  `<noscript>` submit button, but the flow is clumsy without JS.
- No pagination anywhere. The library renders every entry. readlog-dotnet does the
  same, and it is fine for the size of library this app is for.
- The public feed is capped at 20 entries with a 60 second cache, matching the
  source. A busy instance would want more than that.
- Book covers are hot-linked from covers.openlibrary.org and books.google.com. If
  those are unreachable, the browser shows a broken image rather than the
  placeholder, because the placeholder only appears when the URL is absent, not
  when it fails to load. Same behaviour as the source.

## Verification

Everything below was run on the final state of the branch:

```
php artisan migrate:fresh --seed        # clean database, 12 books, 14 entries
vendor/bin/pest                         # 196 passed, 3 skipped, 503 assertions
vendor/bin/pint --test                  # passed
BOOK_SEARCH_LIVE_TESTS=true \
  vendor/bin/pest --filter=LiveProvider # 1 passed, 2 skipped (no Google key)
```

Plus a manual pass over every route with `php artisan serve`: every page returns
200 except the intended 404s, the security headers are present, reader switching
works, and searching the log page against the real Open Library returns hits with
covers, page counts and years.
