# Architecture

How ReadLog is put together, and where a new piece of code belongs.

This is the file to read before writing anything. [AGENTS.md](AGENTS.md) is the
contract, [docs/INVARIANTS.md](docs/INVARIANTS.md) is what you may not break, and
[docs/RECIPES.md](docs/RECIPES.md) is the step-by-step for common changes. This one
explains the shape.

The machine-readable form of the layout is
[docs/machine/repo-map.json](docs/machine/repo-map.json).

## The one rule

```
HTTP  ->  Controller  ->  Service  ->  Model  ->  Database
          (resolves        (takes       (Eloquent)
           the reader)     int $userId)
```

**Dependencies point one way. A service never knows about HTTP.**

Controllers resolve the acting reader and pass a user id down. Services take that
id as an argument and never read the session, the request, or the container. This
is why every ownership rule in the app is testable without a browser, and it is the
single convention most worth preserving.

Two exceptions exist and both are deliberate:

- `CurrentUser` holds a `Session` on purpose. It is the one place session state is
  read, and it lives in `app/Services/` only because it is the thing controllers
  ask for the reader.
- `ReadLogService::embedIfPossible()` resolves `OllamaClient` from the container at
  call time rather than injecting it, so that the optional AI dependency is not
  constructed on every request that touches the reading log.

## Request lifecycle

Three of the four things that happen to every request are not visible in
`routes/web.php`, which is where a newcomer looks first.

1. **The `web` group is implicit.** No route says `->middleware('web')`. It is
   applied because `bootstrap/app.php:13` passes the file as `web:`. Sessions,
   cookie encryption, CSRF verification and the shared `$errors` bag all come from
   there. `resources/views/partials/errors.blade.php` depends on it.

2. **Two middleware are appended globally** in `bootstrap/app.php`, so they wrap
   every response including error pages:
   - `SecurityHeaders` sets `X-Content-Type-Options`, `Referrer-Policy`,
     `X-Frame-Options` and a strict Content-Security-Policy. The policy is
     `script-src 'self'`, which is why `public/js/site.js` exists at all and why no
     template may carry an inline script tag or an `onclick` attribute. It also
     sets `X-ReadLog-App`, which is not a security header: it marks a response as
     this app's, so the portal in front of it can tell one of our 404s from the
     404 the project sharing that funnel port answers with when our mount is
     absent. nginx sets the same header on the responses it generates itself.
   - `PortalPrefix` makes generated URLs survive being served under a path on
     another host. The portfolio site serves this app at
     `mikkonumminen.dev/readlog-laravel`; the mount strips its own prefix, so the
     app sees `/library` and would generate links that escape the mount. Two
     headers, `X-Portal-Host` and `X-Portal-Prefix`, announce where the visitor
     really is. Both are validated for shape rather than trusted: a hostname
     pattern and one clean path segment. Anyone talking to the app directly can
     send them, and all they change is where that sender's own links point, which
     is the same power `X-Forwarded-Host` already grants. Nothing is cached, so
     nobody can poison another visitor's page.

3. **`demo.user` is an alias** for `RequireDemoUser`, registered in
   `bootstrap/app.php`. It stands in for the `[Authorize]` attribute the .NET
   source puts on four page models. With no login page to redirect to, an unseeded
   database sends the visitor home with an explanation instead.

4. **`throttle:ask` is conditional.** It is on `library.index` only, and the limiter
   in `AppServiceProvider::boot()` returns `Limit::none()` unless the request
   carries a non-blank `?ask=`. A plain library page load is never counted. Ten
   questions a minute per address is the bound, because one question costs seconds
   of a local model and the app goes on a public URL for demos.

There is also a view composer in `AppServiceProvider::boot()` for
`partials.demo-user`. Blade has no ambient principal the way a Razor view has
`User`, so the reader switcher gets its data from a composer rather than reaching
into the container from inside a template.

## Routes

Every URL the app answers. Kept identical to the .NET source where the source has
one, so links from its screenshots and notes still resolve. The generated form is
[docs/machine/routes.json](docs/machine/routes.json).

| Method | URI | Name | Middleware | Action |
| --- | --- | --- | --- | --- |
| GET | `/` | `feed` | web | `FeedController@index` |
| GET | `/book` | `book.show` | web | `BookController@show` |
| POST | `/demo-user` | `demo-user.update` | web | `DemoUserController@update` |
| GET | `/library` | `library.index` | web, demo.user, throttle:ask | `LibraryController@index` |
| GET | `/library/{entry}/edit` | `entries.edit` | web, demo.user | `ReadEntryController@edit` |
| PUT | `/library/{entry}` | `entries.update` | web, demo.user | `ReadEntryController@update` |
| DELETE | `/library/{entry}` | `entries.destroy` | web, demo.user | `ReadEntryController@destroy` |
| GET | `/log` | `log.create` | web, demo.user | `LogController@create` |
| POST | `/log` | `log.store` | web, demo.user | `LogController@store` |
| GET | `/account` | `account.show` | web, demo.user | `AccountController@show` |
| GET | `/up` | none | web | framework health closure |

Three details that catch people:

- `{entry}` carries `->whereNumber('entry')`, so `/library/abc/edit` is a route
  miss and a 404, never a controller call.
- `GET /book` takes no id. The book is identified by `?title=`, `?author=` and
  `?cover=` query parameters, because the page is reachable for books that are not
  in the catalogue yet.
- `POST /demo-user` sits deliberately outside the `demo.user` group. Switching
  reader has to work before there is a reader.

Templates link by route name, never by literal path. That is what lets the app be
served under a path prefix.

## Layers, directory by directory

### `app/Http/Controllers/`

One controller per page, one action per route. The counterparts of the .NET page
models. A controller resolves the reader, calls a service, and returns a view. No
queries, no business rules.

`BookController` does slightly more, on purpose: it runs the description through
`BookDescriptionSanitizer` and refuses any info link whose scheme is not http or
https, so the template has nothing left to decide.

`LogController` handles two stages off one URL, switching on whether `?olid=` is
present. That is the source's design and it is kept, because the "change book" link
and the browser back button both depend on the two stages being addressable.

### `app/Services/`

The reading-log domain and the two book providers. Every method takes
`int $userId` first.

- **`ReadLogService`** is the domain: logging a finished book, the reader's library,
  the "have I read this?" lookup, edit, delete, account statistics, and the public
  feed. There is no `IReadLogService` interface, unlike the source, because the
  container resolves the concrete class and the tests run against a real in-memory
  database rather than a mock, so the interface would have had one implementation
  and no caller that cared.
- **`BookSearchService`** queries both providers concurrently through `Http::pool`,
  concatenates Open Library first, then de-duplicates by normalised title plus
  author, keeping whichever duplicate carries more metadata. Ties keep the first
  seen, which is what makes the ordering matter.
- **`BookDetailsService`** fetches and caches Google Books detail for one title and
  author. It caches successes and deliberately does not cache misses.
- **`BookDescriptionSanitizer`** runs provider HTML through Symfony's sanitiser
  before it reaches a template.
- **`CurrentUser`** resolves the acting reader from the session.

### `app/Services/External/`

One client per provider. Each exposes `requestSearch()` and `parseSearch()` as a
split pair rather than one method that does both, because `Http::pool` needs every
request handed over before any response exists. That split is the visible cost of
PHP having no `await`, and it is the one place where the two languages genuinely
differ rather than spelling the same thing differently.

Each response is unwrapped in its own try/catch after the pool resolves, so one
provider failing cannot sink the other.

### `app/Support/`

`final readonly` data carriers passed between layers: `LibraryEntry`,
`BookSearchResult`, `BookDetails`, `BookSummary`, `PublicRead`, `AccountStats`,
`LogBookData`, `UpdateReadEntryData`, `AskResult`, and `Redact`.

`PublicRead` carries no user field at all. That absence is the point: the public
feed cannot leak a reader's identity because the projection has nowhere to put it.

### `app/Models/`

Eloquent models with `@property` docblocks. The docblocks are not decoration:
PHPStan infers attribute types from the columns and would read `format` as a string
and `finished_at` as a string, which the casts contradict.

### `app/Exceptions/`

Two exceptions, and the rule behind both: one exception per way a thing can fail,
so a caller has exactly one thing to catch and exactly one decision to make.
`OllamaUnavailableException` covers unreachable, refused, timed out and answering in
the wrong shape alike, because the decision is the same in every case, which is to
degrade. `DuplicateReadEntryException` is the domain's own name for a unique-index
violation that was confirmed to be a real duplicate.

### `app/Providers/AppServiceProvider.php`

`register()` is deliberately empty, and that emptiness is the point: Laravel's
container auto-wires any concrete class with type-hinted constructor dependencies,
so `ReadLogService`, `CurrentUser` and every controller need no line. `Program.cs`
has to name each one.

`boot()` holds the two things that do need saying: the conditional `throttle:ask`
limiter, and the view composer for `partials.demo-user`. **Do not bind
`CurrentUser` here.** It holds a Session, and a binding that outlives one request
serves the previous request's reader to the next one. It was tried twice.

### `app/Casts/DateOnly.php`

PHP has no date-only type. Laravel's built-in `date` cast reads back as midnight
but writes a full datetime string, which would break the unique
`(user_id, book_id, finished_at)` index against the `YYYY-MM-DD` value an HTML date
input produces, and would stop readlog-dotnet reading the same file.

### `resources/views/`

Blade. One plain stylesheet, no framework, no build step. No business logic in a
template, and no inline script tags.

## Data model

Two tables carry the app. Everything else is stock Laravel or supports the AI
search.

### `books`

The shared catalogue. One row per work, used by every reader.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `title` | varchar(255) | indexed |
| `author` | varchar(255) | nullable |
| `cover_url` | varchar(255) | nullable |
| `open_library_id` | varchar(255) | **unique where present**, the natural key for find-or-create |
| `page_count` | integer | nullable |
| `first_publish_year` | integer | nullable |
| `created_at` | timestamp | no `updated_at`, matching the .NET entity |

### `read_entries`

One reader finishing one book on one date.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `user_id` | bigint FK | cascade on delete |
| `book_id` | bigint FK | **restrict** on delete |
| `format` | varchar(16) | the readable name, not an ordinal |
| `finished_at` | date | date only, no time |
| `rating` | integer | nullable. **Null is unrated; 0 is a real rating** |
| `created_at` | timestamp | no `updated_at` |

Unique on `(user_id, book_id, finished_at)`. Indexed on `user_id` and
`finished_at`.

The cascade and restrict pair mirrors the .NET `DeleteBehavior.Cascade` and
`DeleteBehavior.Restrict`, which in turn mirror the original Prisma schema:
deleting a reader removes their entries, and a catalogue book that entries still
point at cannot be deleted.

### `read_entry_embeddings`

One vector per reading entry, for the AI search. `read_entry_id` is unique and
cascades on delete. `vector` is a plain **text** column holding JSON, not a
`pgvector` column, because the app has to run identically on SQLite and PostgreSQL
and a reader's library is hundreds of rows rather than millions. `content_hash`
and `model` together decide whether a stored embedding is stale.

### The gap worth knowing

The .NET schema has a check constraint bounding the rating to 0 through 5. Laravel's
schema builder has no check-constraint API and SQLite cannot add one to an existing
table, so **the bound lives in request validation and in the model, not in the
database.** That is a real loss of a database-level guarantee, and it is pinned by a
test that asserts the gap exists so that closing it later is deliberate.

## Ask your library

The one feature with no counterpart in the .NET source. Three layers, each allowed
to fail downward, which was the rule set for it before it existed.

```
question
   |
   v
[1] LibraryQuestion::parse   format / rating / year  ->  WHERE clauses
   |                          deterministic, no model
   v
[2] EntryEmbedder            embed the question, cosine-rank the candidates
   |                          top 8 by default
   v
[3] LibraryAsk               chat model phrases an answer over those 8, as JSON
   |                          with the ids it relied on
   v
AskResult -> the page
```

**Layer 1** pulls format words, ratings and years out of the question with plain
patterns. Every pattern requires a rating word next to the number, so "at least 4
books" and "over 3 weeks" are not ratings. A bare four-digit number is not a year:
"the 1984 one" is a title. Filters that match nothing are relaxed rather than
answering "nothing", and the page shows that they were dropped.

**Layer 2** renders each entry as one short sentence group (title, author, format,
rating, finished date, publication year, page count), embeds it once, and stores it.
The stored text carries a task prefix that is inside the hash, so changing the
prefix re-embeds everything. Ranking is cosine similarity in PHP, which for a few
hundred entries is well under a millisecond.

**Layer 3** shows the top entries to a small chat model at temperature 0 with
`format: json`, and asks for a short answer plus the ids it relied on.

### The containment boundary

This is the security-relevant part. What bounds the model:

- It is shown **only rows the database already selected**, and the selection is
  scoped with `where('user_id', $userId)` before anything else happens.
- Its returned ids are checked against an allow-list of exactly the ids printed
  into the prompt, and they are then used only as a **filter over the already-fetched
  entries**, never as a lookup key. A hallucinated or cross-reader id cannot fetch a
  row; it can only be discarded.
- Its authority is exactly one free-text string plus a subset selection of a list
  that was already fixed. It cannot widen the result set, cannot write, cannot
  reach the network, and has no tool use.
- The answer is rendered with Blade escaping.
- Entries it saw but did not cite stay visible under the answer, so a miss is a
  miss the reader can see.

**What is not bounded, stated plainly:** book titles and authors are reader-supplied
text that reaches the prompt, and the entries block is delimited only by a header
line and newlines. There is no data and instruction fencing, so a title containing
a newline followed by text mimicking the prompt's own framing is structurally
indistinguishable from it. A successful injection cannot reach data outside the
entries already shown, and cannot write anything, but it can make the model emit
arbitrary prose to the reader or cite the wrong subset. There is no adversarial
regression fixture in the suite yet; it is recorded in [TODO.md](TODO.md).

Every failure mode raises one exception, `OllamaUnavailableException`, so callers
have exactly one thing to catch and exactly one decision to make, which is to
degrade.

## Testing architecture

Pest 4. `tests/Feature/` for anything needing a database or an HTTP request,
`tests/Unit/` for pure tests that should not pay for a database.

Two properties of the suite are load-bearing:

- **`RefreshDatabase`** migrates a fresh schema and wraps each test in a transaction
  that is rolled back, so tests never see each other's rows.
- **`Http::preventStrayRequests()`** is on for every Feature test. An unfaked
  outbound request fails the test naming the URL. This is not belt and braces: when
  `BookSearchService` was first wired into the log page, the page tests started
  calling openlibrary.org for real, still passed, and the only symptom was the suite
  going from 2.8 to 13 seconds.

Three tests call the real provider APIs and assert response *shape*, never specific
data. They are skipped unless `BOOK_SEARCH_LIVE_TESTS=true`.

The suite is safe to run twice at once, and that is deliberate rather than
incidental. `readlog:snapshot` builds a throwaway SQLite database named for the
process that made it, because a fixed path made the checkout shared mutable state:
two overlapping runs, and one truncated the other's file while the other deleted
it, turning the gate red for reasons unrelated to the change. See decision 121. Any
new test that writes to a fixed path under `storage/` reintroduces that, so name
the file for the process.

## Verification

```bash
composer verify
```

Pint, PHPStan level 6, the Pest suite, and `readlog:docs-check`. All four must pass,
and none of them reaches the network.

CI runs three jobs. `sqlite` is the developer's loop. `postgres` is the production
claim: the whole migrate, seed and test cycle against a stock `postgres:16`
container configured through nothing but the six documented `DB_*` variables, so if
it is green the app runs on any standard PostgreSQL rather than one provider's
flavour. `compose` brings the stack up from a bare checkout and probes it, including
the forwarded-header behaviour a tunnel depends on.

## Where to add a new thing

| You are adding | Put it in | Then |
| --- | --- | --- |
| A business rule | `app/Services/` | Takes `int $userId`. Add a service test |
| A page | `routes/web.php` + `app/Http/Controllers/` + `resources/views/` | Add an HTTP test that another reader cannot see it |
| A validation bound | `app/Http/Requests/` | Nothing the database can enforce belongs anywhere else |
| A field on an entry | Migration, model, DTO, request, service, view | Follow [docs/RECIPES.md](docs/RECIPES.md); it spans ten files |
| A book provider | `app/Services/External/` | Register it in the pool. It must fail without sinking the others |
| Anything touching Ollama | `app/Services/Ai/` | It must still work with Ollama off |
| A cross-cutting request concern | `app/Http/Middleware/` | Decide global or route, and say which in the decision entry |
| An operator command | `app/Console/Commands/` | Non-zero exit on failure |
| A shared value object | `app/Support/` | `final readonly`, no behaviour beyond derived values |
| JavaScript | `public/js/site.js` | The CSP forbids anything inline |

If none of these fits, the shape of the change is probably wrong. Say so rather
than inventing a layer.

## What the app deliberately does not have

Each of these is a recorded decision, not an oversight. Adding one is a change of
direction and needs its own entry in [DECISIONS.md](DECISIONS.md).

- **Authentication.** Version 1 scope did not include it, and the session-backed
  demo reader keeps the ownership rules real and testable in its place.
- **A JavaScript build step.** Clone to rendered page is one Composer command.
- **Pagination.** The source has none either, and it is fine for the size of library
  this app is for.
- **A service interface layer.** One implementation, no caller that cares.
- **`pgvector` or any extension-dependent SQL.** The app runs on SQLite too.
