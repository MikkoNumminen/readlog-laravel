# Status

Where this project stands, what each pull request contains, and what was not done.

Last updated: 2026-08-19.

## Summary

The migration of [readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet)
to Laravel 13 is **complete for version 1 scope**. Every feature in that scope is
implemented and tested, the app runs from a clean clone with one Docker command
(or four PHP ones), and the test suite is green on SQLite and on Postgres.

**Beyond the port**, one feature: "ask your library", a natural-language search
over the reader's own entries answered by a local Ollama, degrading to the title
search when Ollama is absent (PRs 16, 18, 19). And a desktop control panel for
the locally hosted app (PR 17).

**Hosting**: the app runs locally on the author's machine and is put on a public
URL only on demand, through a Cloudflare quick tunnel, for the length of a demo.
There is no hosted copy. Cloud deployment was worked on and then dropped as a
deliberate decision, on cost; the section "Hosting: what is automated and what is
manual" below has the whole story. The .NET version remains the one hosted
publicly.

Version 1 scope, from the brief, was four things: books CRUD matching the .NET
model, the reading-entry log, the search the source has, and the multi-source
Open Library plus Google Books lookup with its merge logic. All four are done.
Authentication was not in scope and is not implemented; see below.

```
345 passing tests, 3 skipped (live API), on SQLite and on Postgres 16; PHPStan level 6
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

Merged. 5 commits.

MIGRATION.md, README.md, TODO.md and this file. No code changes, plus one licence
file that the README had been claiming for three phases without it existing.

Self-review found four inaccuracies in my own prose rather than any bug: the
missing licence, an invented count of service registrations in `Program.cs`, a
wrong description of what the `composer setup` script runs, and a phrase from my
own list of things not to write. Every other number in the documents was checked
against the repository.

### [PR 5: complete the PR 4 entry](https://github.com/MikkoNumminen/readlog-laravel/pull/5)

Merged. 1 commit. The one line STATUS.md could not carry until PR 4 had merged.

### [PR 6: local runtime](https://github.com/MikkoNumminen/readlog-laravel/pull/6)

Merged. 6 commits. Run 2, phase 1.

Two halves. First, the portability work that a cancelled cloud-deployment attempt
left behind and that was kept on purpose: the reading-log service behaves the
same on Postgres as on SQLite (case-insensitive lookup by lower-casing both sides
in SQL; savepoints around the two guarded inserts, because Postgres aborts a
transaction after a constraint violation), the demo seeder is safe to run on
every start (fixed date anchor; seeds only into an empty catalogue), and CI runs
the whole migrate, seed and test cycle against a stock `postgres:16` container
using only the six documented `DB_*` variables.

Second, Docker Compose: `docker compose up --build -d --wait` from a fresh clone,
nginx in front of php-fpm, SQLite in a named volume, `APP_KEY` generated on
first start, and `compose.postgres.yaml` to run the same app on Postgres. A CI
job brings the stack up from a bare checkout and probes it, on both databases.

Self-review found five things, the notable one being that my own portability fix
regressed SQLite for non-ASCII titles (`mb_strtolower` in PHP versus SQLite's
ASCII-only `lower()`); fixed by lower-casing both sides in SQL, with a Finnish
title as the test.

### [PR 7: on-demand public exposure](https://github.com/MikkoNumminen/readlog-laravel/pull/7)

Merged. 5 commits. Run 2, phase 2.

`config/trustedproxy.php` reading `TRUSTED_PROXIES`, so the app believes the
forwarded scheme and host from nginx and from a tunnel; `readlog:smoke`, a
seven-row pass/fail check of a running instance; two compose profiles for a
Cloudflare quick tunnel and a named tunnel; `scripts/tunnel-up.sh` and
`tunnel-down.sh`; and DEMO.md. The compose CI job now sends nginx the exact
headers Cloudflare's edge adds and asserts https links and a Secure cookie.

Self-review found the scripts committed without their executable bit (Git on
Windows does not record it), a stale `.tunnel-url` that could have been copied
into the image, and three tests that were wrong when first written.

### Pull requests 8 to 26, in one table

Later runs were smaller and are recorded here in one line each; every PR carries
its own self-review.

| PR | What |
| --- | --- |
| [8](https://github.com/MikkoNumminen/readlog-laravel/pull/8) | Docs close-out for the local-hosting decision; hosting kept as an open question. |
| [9](https://github.com/MikkoNumminen/readlog-laravel/pull/9) | Review pass over PRs 1 to 8: fifteen verified findings fixed. |
| [10](https://github.com/MikkoNumminen/readlog-laravel/pull/10) | `readlog:snapshot`: a static, browsable copy of the seeded app, published under the portfolio at /readlog-laravel. |
| [11](https://github.com/MikkoNumminen/readlog-laravel/pull/11) | README: link the snapshot and say what it does and does not do. |
| [12](https://github.com/MikkoNumminen/readlog-laravel/pull/12) | Review of the snapshot generator: five findings fixed (a second run in one process wrote nothing; `--out=.` would have deleted the repo). |
| [13](https://github.com/MikkoNumminen/readlog-laravel/pull/13) | Docs: the tunnel verified end to end by hand. |
| [14](https://github.com/MikkoNumminen/readlog-laravel/pull/14) | Editing an entry onto an occupied date shows a message instead of a 500 (a ported hole, closed). |
| [15](https://github.com/MikkoNumminen/readlog-laravel/pull/15) | PHPStan via Larastan at level 6, in CI. |
| [16](https://github.com/MikkoNumminen/readlog-laravel/pull/16) | AI search 1/3: `OllamaClient`, one embedding per entry, `readlog:embed`, best-effort embedding on write. |
| [17](https://github.com/MikkoNumminen/readlog-laravel/pull/17) | ReadLog Control: a desktop panel (status board, on, off, tunnel on and off, smoke, embed, warm, ask). |
| [18](https://github.com/MikkoNumminen/readlog-laravel/pull/18) | AI search 2/3: "ask your library", three layers, degrading to the title search. |
| [19](https://github.com/MikkoNumminen/readlog-laravel/pull/19) | AI search 3/3: documentation, this table, TODO.md trimmed. |
| [20](https://github.com/MikkoNumminen/readlog-laravel/pull/20) | Review pass over PRs 16 to 19: thirteen findings fixed (parser false positives, nginx timeout, throttle, embedding race, control panel edge cases). |
| [21](https://github.com/MikkoNumminen/readlog-laravel/pull/21) | Snapshot banner names the AI ask box as inactive too. |
| [22](https://github.com/MikkoNumminen/readlog-laravel/pull/22) | The live app under mikkonumminen.dev/readlog-laravel: `PortalPrefix`, the funnel path mount, on means everything. |
| [23](https://github.com/MikkoNumminen/readlog-laravel/pull/23) | Portal: the app names itself in every response (`X-ReadLog-App`, set by the middleware and by nginx alike), so the portal can tell this app's 404 from the co-tenant's and fall back to the snapshot only when readlog is truly off. |
| [24](https://github.com/MikkoNumminen/readlog-laravel/pull/24) | Making the repository legible to a coding agent: ARCHITECTURE.md, AGENTS.md, CONTRIBUTING.md, invariants, recipes, glossary, `docs/machine/*.json`, and `readlog:docs-check` in `composer verify` and CI. |
| [25](https://github.com/MikkoNumminen/readlog-laravel/pull/25) | ARCHITECTURE.md names the `X-ReadLog-App` header the PR 23 and 24 merge introduced, a drift `readlog:docs-check` cannot see. |
| [26](https://github.com/MikkoNumminen/readlog-laravel/pull/26) | A project-local Laravel audit skill (the `mikko-*` suite is PHP-blind), its first run (121 candidates, zero findings), and its opt-in reviewed-fix-PR phase. |

## Hosting: what is automated and what is manual

**Automated, in the repository:**

- Fresh clone to a running, seeded app: `docker compose up --build -d --wait`.
- The same on a stock Postgres: `-f compose.yaml -f compose.postgres.yaml`.
- Correct behaviour behind a proxy or tunnel: `TRUSTED_PROXIES`, https links,
  Secure session cookie, real client address. Tested, and exercised in CI against
  the running stack with Cloudflare's headers.
- Opening and closing a temporary public URL: `scripts/tunnel-up.sh [--smoke]`,
  `scripts/tunnel-down.sh`.
- Checking a running instance from outside: `php artisan readlog:smoke --url=...`.
- CI proving all of the above on every push: SQLite suite, Postgres suite,
  compose stack on both databases with the proxy check.

**Verified by hand on 2026-08-19, and not something CI can do:** a real
Cloudflare quick tunnel was opened to the running compose stack, and every step
of the DEMO.md walk-through passed: `readlog:smoke` run from inside the app
container against the public URL (out through Cloudflare, back through nginx),
https links on the tunnel host, a Secure session cookie, the reader switch and a
logged book both surviving the round trip (session and CSRF), and the URL
answering 502 within five seconds of the tunnel closing. Two notes from doing
it: Docker Hub was unreachable from that network, so the native `cloudflared`
path from DEMO.md was used instead of the compose profile; and Cloudflare's
edge rewrites e-mail addresses on `trycloudflare.com` pages into
"[email protected]" links (its Scrape Shield feature), which is cosmetic and
recorded in DEMO.md.

**What was dropped, and why.** Run 2 began as Laravel Cloud deployment support:
a Postgres-backed instance on a paid platform with a public URL. The
database-portability work was done and is what PR 6 opens with. Before anything
cloud-specific was pushed, the author redirected the run: no cloud provider, no
recurring cost, no third-party account holding a copy of the app. A
`MANUAL-STEPS.md` written against Laravel Cloud's dashboard was dropped without
reaching a PR. What was kept is everything that makes the app runnable anywhere:
env-driven configuration, SQLite and Postgres both tested, a container that
starts clean, the health route, and the smoke check. Nothing in the repository
names a hosting provider. If hosting is ever wanted, TODO.md lists the options
that exist without recommending one.

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

### Static analysis: PHPStan level 6, in CI

readlog-dotnet sets `WarningsAsErrors=nullable`, so a possible null dereference
does not compile. The nearest thing PHP has is a separate analyser: PHPStan via
Larastan runs at level 6 in CI (`composer analyse` locally). Turning it on found
twenty-one things, of which one was a real dead branch (a null check on a
NOT NULL column) and the rest were the type information Eloquent never carries:
`@property` declarations on the three models, the generic on the custom date
cast, and a positional-array `where()` in the smoke check that happened to work.
Levels above 6 start arguing about union types in ways that produce more
annotations than findings for an app this size.

### No hosted instance

readlog-dotnet runs on Azure App Service. This app does not run anywhere but the
author's machine, and that is a decision, not a gap: see "Hosting" above. What
readlog-dotnet's Program.cs does for a hosted life (forwarded headers, HSTS,
persisted data-protection keys, an Azure deploy workflow) is either done here in
its local form (forwarded headers via `TRUSTED_PROXIES`, a Dockerfile and compose
stack, `/up`) or has no counterpart because there is no host (HSTS, key
persistence beyond the storage volume, a deploy pipeline).

### AI features

"Ask your library" is implemented (README has the description, DECISIONS.md
#94 to #108 the reasoning). It was built after version 1 was closed and
compared, so the comparison in MIGRATION.md stays honest: it is called out
there as the one thing with no .NET counterpart. What the model knows is what
the log knows, title and author and the entry's own facts, so questions about
plot only work for books the model has heard of. The recommendation idea and
the LLM-assisted metadata merge remain parked in TODO.md.

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

## Documentation as a checked artifact

As of PR 24 the documentation is not only written, it is checked. `composer verify`
runs `php artisan readlog:docs-check`, which fails the build when a relative link
does not resolve, when a repository path named in prose does not exist, when a route
exists that `ARCHITECTURE.md` does not document, when `config/services.php` reads an
environment variable `.env.example` does not explain, when the generated files under
`docs/machine/` are stale against their prose sources, or when a document contains an
em dash.

It was written because the counts in this file and in README.md had been wrong for
two pull requests and nothing had noticed: one paragraph said 222 tests, another
said 326, and the tree held 338. The command deliberately does not run the suite,
because a suite that counts itself proves nothing; CI compares the real run's case
total against `docs/machine/test-counts.json` from outside instead.

It was written by scoring the repository against a published rubric
([docs/AI-FIRST.md](docs/AI-FIRST.md)) with three independent graders, three times.
The score went 4.8, then 8.5, then 9.0, and each round found real bugs by running
the repository rather than reading it: a fixed-path temp database that made the
suite red on roughly a third of runs, a PHP version constraint that was two minor
versions too low for the committed lockfile, a test assertion that collided with
process ids containing "405", and a CI step that read the wrong XML node and would
have failed every run. Decisions 121 to 129.

The agent-facing surface it guards is `ARCHITECTURE.md`, `AGENTS.md`, `CLAUDE.md`,
`CONTRIBUTING.md`, `docs/INVARIANTS.md` (59 invariants, each naming its guarding
test), `docs/RECIPES.md`, `docs/GLOSSARY.md`, `docs/AI-FIRST.md` and the JSON under
`docs/machine/`.

## Verification

Everything below was run on the final state of the branch:

```
php artisan migrate:fresh --seed        # clean database, 12 books, 14 entries
composer verify                         # pint, phpstan level 6, pest, docs-check: green
vendor/bin/pest                         # 345 passed, 3 skipped, 1206 assertions (SQLite)
DB_CONNECTION=pgsql ... vendor/bin/pest # 345 passed, 3 skipped (Postgres 16)
vendor/bin/pint --test                  # passed
BOOK_SEARCH_LIVE_TESTS=true \
  vendor/bin/pest --filter=LiveProvider # 1 passed, 2 skipped (no Google key)
docker compose up --build -d --wait     # app=healthy web=healthy, every route 200, /.env 403
docker compose -f compose.yaml -f compose.postgres.yaml up -d --wait
                                        # driver pgsql, 12 books counted inside psql
docker compose exec app php artisan readlog:smoke --url=http://web
                                        # 6 PASS, 1 WARN (no Google key)
```

As of PR 24 the same commands give 345 passed, 3 skipped, on both databases, and
`composer analyse` (PHPStan level 6) is clean; CI runs all of it on every push.

Plus a manual pass over every route with `php artisan serve` and again through
nginx in compose: every page returns 200 except the intended 404s, the security
headers are present, reader switching works, and searching the log page against
the real Open Library returns hits with covers, page counts and years. Sending
nginx `Host: demo.trycloudflare.com` and `X-Forwarded-Proto: https` produced
https links and a Secure session cookie. The tunnel itself was not opened; DEMO.md
lists the checks that remain.
