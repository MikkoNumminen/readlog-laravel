# TODO

Recorded, not implemented. Nothing in this file is in the codebase.

## AI-assisted natural-language search over your own library

The one feature worth building next, and the reason the rest of this file is
short.

**What it does.** Ask the library a question in ordinary language instead of
matching a title substring. "That Finnish book about a lighthouse I read a couple
of summers ago." "The audiobooks I gave five stars." "Something like Piranesi but
shorter." Today `ReadLogService::checkIfRead()` can only answer "does any title I
have logged contain this string", which is the whole of what readlog-dotnet does
too.

**How it would work.**

- Laravel calls a local [Ollama](https://ollama.com/) instance over HTTP on
  localhost. No cloud, no key, no per-token cost, nothing leaving the machine.
- Embeddings over books and reading entries, stored in a new table alongside them
  and recomputed on write. With a library of a few hundred books, brute-force
  cosine similarity in PHP is fast enough, so no vector extension is needed.
- The natural-language query is embedded the same way, the nearest entries are
  retrieved, and a small local model turns them into an answer that cites the
  entries it used.

**The two-layer principle.** This follows the same
shape as the author's
[feedback-intelligence](https://github.com/MikkoNumminen/feedback-intelligence)
project: a deterministic layer in front of a model, never a model in front of the
data.

- Layer one is the existing SQL. Filters that can be expressed exactly (format,
  rating, date range, title match) are executed as queries, not inferred.
- Layer two is the model, and it only ever ranks or phrases what layer one
  retrieved. It is not allowed to invent a book, and every result it returns has
  to correspond to a real `read_entries` row.

**Degrading cleanly is a hard requirement.** The app must never depend on Ollama.
If it is not running, not installed, slow, or returns something unusable, the
search box falls back to the existing `checkIfRead()` behaviour and says so in one
line of text. That is the same rule `BookSearchService` already follows for Open
Library and Google Books, and there is precedent in this repository for testing
it: fake the transport, force the failure, assert the fallback.

**Why it is not in version 1.** It is a feature readlog-dotnet does not have, and
version 1 was scoped as a faithful port. Building it first would have made the
comparison in MIGRATION.md dishonest.

## AI candidates considered and parked

**Recommendations from rating history.** "You gave these five stars, here are three
you have not logged." Parked because the interesting version needs a corpus this
app does not have. With one reader's few dozen ratings, the honest implementations
are content similarity over what Open Library and Google Books already return,
which is not much of an AI feature, or a model guessing from titles, which is a
recommendation engine with no evidence behind it. Worth revisiting if the
catalogue ever holds many readers' entries.

**LLM-assisted merge of conflicting multi-source metadata.** The natural next step
for `BookSearchService::deduplicate()`. Today, when Open Library and Google Books
disagree about the same book, the winner is whichever record has more non-null
cover and page-count fields, and everything the loser knew is discarded. There is
a test that spells this out
(`tests/Feature/Services/BookSearchServiceTest.php`, "merges conflicting metadata
by richness, not by correctness"): in that case 1965 was almost certainly the
better first-publish year and it is replaced by 1990.

A model could do field-level merging with a reason for each choice. Parked for
now for two reasons. The deterministic version has to come first: a field-level
merge with sensible rules (prefer the earlier plausible year, prefer the longer
title only when it contains the shorter one, prefer a page count within a sane
range) would fix most of it with no model at all. And this runs on every search
result, so it is the worst possible place to add a per-item model call. If it
happens, it happens behind a cache and only for the handful of records that
actually conflict.

## Infrastructure

**Cloudflare Tunnel to show the app in an interview.** `cloudflared tunnel --url
http://localhost:8000` gives a public HTTPS URL for a locally running app with no
account and no deployment. Attractive for a demo. Not set up in advance, because a
quick tunnel's URL changes every time it starts, so there is nothing to prepare
beyond having `cloudflared` installed. Two things would have to be checked first:
the `Content-Security-Policy` in `SecurityHeaders.php` uses `form-action 'self'`,
which is fine through a tunnel, and `APP_URL` would need to match the tunnel host
or generated links point at localhost. Start it when it is needed, not before.

**Docker Compose instead of `php artisan serve`.** Would remove the "install PHP
8.3 with these extensions" step and the Windows CA-bundle problem in the README,
and would pin the runtime so the app behaves the same everywhere. Against it: the
app is deliberately dependency-free and a single `php artisan serve` is the
simplest thing that demonstrates it. readlog-dotnet has a Dockerfile because it
deploys to Azure App Service; this app does not deploy anywhere. Worth doing if
anyone other than the author needs to run it.

## Correctness and tooling

**Authentication.** readlog-dotnet has ASP.NET Core Identity with local accounts
and an optional Google login. Version 1 of this port has none, deliberately, and
ships `app/Services/CurrentUser.php` as a session-backed demo reader switcher in
its place. Per-user ownership is fully modelled and tested, so this is wiring
rather than design: `CurrentUser::get()` becomes `auth()->user()`, the
`demo.user` middleware becomes `auth`, `actingAsReader()` in `tests/Pest.php`
becomes `actingAs()`, and the switcher is deleted. Laravel Fortify or a
hand-rolled session login would both do it without adding npm, which Laravel
Breeze would. The users table already carries a `password` column for this.

**A static analyser.** The single largest thing lost in the move. readlog-dotnet
sets `WarningsAsErrors=nullable`, so a possible null dereference does not compile.
PHP has nothing equivalent running before the code does. PHPStan at a high level,
or Psalm, would recover most of it, and CI already has a place to put it next to
`pint --test`. See the "Two guarantees that did not survive" section of
MIGRATION.md.

**The rating check constraint.** The .NET schema bounds `rating` to 0 to 5 at the
database level with `CK_ReadEntry_Rating`. Laravel's schema builder cannot express
a check constraint and SQLite cannot add one to an existing table, so the bound
lives only in request validation. A SQLite trigger, or a raw `CREATE TABLE` in the
migration, would restore it. The test
`tests/Feature/Database/SchemaConstraintsTest.php` currently asserts the gap, so
it is the test that should fail and be rewritten when this is done.

**Editing an entry onto an occupied date returns a 500.** The unique
`(user_id, book_id, finished_at)` index rejects it and nothing catches the
violation, so the user sees an error page. readlog-dotnet has exactly the same
hole, which is why it was ported rather than fixed. `ReadLogService::logBook()`
already has the pattern to copy: catch `UniqueConstraintViolationException`,
confirm by re-querying, and throw `DuplicateReadEntryException`.
