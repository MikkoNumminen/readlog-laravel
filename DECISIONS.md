# Decisions

Every call made during the migration that a reviewer could reasonably have made
differently, with one line of reasoning each. Appended to as the work proceeds,
newest phase last. The detailed write-up lives in
[MIGRATION.md](MIGRATION.md); this file is the running log.

Source of truth for behaviour: [readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet),
read from a local checkout at `D:\koodaamista\Readlog-csharp`.

## Setup

| # | Decision | Reasoning |
| --- | --- | --- |
| 1 | Treated `D:\koodaamista\Readlog-csharp` as the source repo | The brief named a "koodailua" folder and a `readlog-dotnet` project; the only matching checkout on the machine is `koodaamista\Readlog-csharp`, whose remote is `MikkoNumminen/readlog-dotnet`. Same repo, different local folder name. |
| 2 | Installed PHP 8.4.24 and Composer 2.10.2 | Neither was on the machine. PHP 8.4 is the newest release Laravel 13 supports across the board, and it is available as a winget package, so the install is reproducible. |
| 3 | Laravel 13.26.0 | Latest stable at the time of the migration, which is what the brief asks for. |
| 4 | Pest 4 as the test runner, `phpunit.xml` kept | The brief specifies Pest. Pest 4 runs on PHPUnit underneath and still reads `phpunit.xml`, so the config file stays where a PHP developer expects it. |
| 5 | Deleted the Vite front end (`package.json`, `vite.config.js`, `resources/css`, `resources/js`) | The brief rules out a JS framework and asks for an app that runs with `php artisan serve`. Keeping Vite would put `npm install && npm run build` between a clone and a rendered page. |
| 6 | Public GitHub repo `MikkoNumminen/readlog-laravel` | The brief frames this as a public portfolio project whose process is the product, and it asks for pull requests, which need a remote. |

## Phase 1: skeleton and domain

| # | Decision | Reasoning |
| --- | --- | --- |
| 7 | No authentication in version 1 | The brief lists version 1 scope as four items and says "this only"; none of them is auth, and it asks for auth to be recorded in TODO.md if the source has it and it was skipped. ASP.NET Core Identity has no small Laravel equivalent that avoids npm, so porting it properly is its own phase. |
| 8 | Kept per-user ownership anyway, with a session-backed demo user | Read entries belong to a user in the source, and the "your library" and "not found rather than forbidden" rules are part of the behaviour being ported. Dropping users would have quietly removed real logic. |
| 9 | `user_id` is a bigint foreign key, not a GUID string | The GUID string key comes from `IdentityUser`, which is not being ported. Laravel's stock `users` table is bigint-keyed, and matching the framework beats matching a column type whose reason for existing has been removed. |
| 10 | No `CK_ReadEntry_Rating` check constraint | Laravel's schema builder has no check-constraint API, and SQLite cannot add one to an existing table. The 0 to 5 bound moves to request validation. This is a real loss of a database-level guarantee and is pinned by a test that documents the gap. |
| 11 | Hand-written `App\Casts\DateOnly` instead of Laravel's `date` cast | PHP has no `DateOnly`. Laravel's `date` cast reads back as midnight but writes a full datetime string, which would break the unique `(user_id, book_id, finished_at)` index against the `YYYY-MM-DD` value an HTML date input produces, and would stop readlog-dotnet reading the same file. |
| 12 | `created_at` only, no `updated_at` | The .NET entities implement `ICreatedAt` and have no modified-at column. `const UPDATED_AT = null` tells Eloquent to stop looking for the column it would otherwise assume. |
| 13 | Format display helpers live on the enum | C# needs `Models/FormatDisplay.cs` as a separate static extension class because an enum cannot carry behaviour. A PHP backed enum can, so `label()`, `pluralLabel()` and `icon()` go where they belong. |
| 14 | Seed data carries real Open Library work keys and cover ids | `open_library_id` is the natural key the log flow uses for find-or-create. Made-up keys would let a seeded book and a searched book duplicate each other, which would misrepresent how the app behaves. |
| 15 | Added model factories, which the source has no counterpart for | The .NET tests build entities inline. Laravel test code leans on factories hard enough that not having them would have made every later test noisier. |

### Added during the phase 1 self-review

| # | Decision | Reasoning |
| --- | --- | --- |
| 16 | Added `.github/workflows/ci.yml` | readlog-dotnet gates every PR on build plus tests. This project's evidence is its pull requests, so they should carry the same green check. PHP has no compile step, so the workflow migrates a clean database as well as running the suite. |
| 17 | Trimmed AWS, Redis, Memcached and Vite entries out of `.env.example` | The brief rules out anything needing hosting or a paid service. Every one of those keys has a working default in `config/`, so listing them only suggests the app might use them. |
| 18 | Seeder assigns user properties directly instead of via `updateOrCreate` | Review finding: `email_verified_at` is not on the mass-assignment allowlist, so `fill()` drops it silently. It worked only because `artisan db:seed` runs seeders inside `Model::unguarded()`. A seeder should not depend on the caller having switched the guard off, so there is now a test that runs it with guards on. |

## Phase 2: features and UI

| # | Decision | Reasoning |
| --- | --- | --- |
| 19 | No `IReadLogService` interface | The .NET version has one so the class can be faked and registered by contract. Laravel's container resolves the concrete class by name, and these tests run against a real in-memory SQLite database rather than a mock, so the interface would have had one implementation and no caller that cared which. |
| 20 | The service returns readonly DTOs, not Eloquent models | Laravel would happily hand models to the views. The `PublicRead` projection exists precisely so the public feed cannot reach a user, which the .NET `PublicReadDto` states in a comment; a projection makes it true rather than intended. |
| 21 | The public feed caches arrays of scalars, not objects | `IMemoryCache` holds a live reference and never serialises. Every Laravel store except the in-process array one serialises, and Laravel 13 ships `'serializable_classes' => false`, so caching objects writes cleanly and fails on the next read. Caching scalars keeps that secure default rather than widening an allowlist. |
| 22 | No route-model binding for read entries | Binding would load the row before anyone asked whose it is, leaving ownership as a second step. Passing the id to the service keeps "not found" and "not yours" one query and one answer, which is why the source answers 404 and not 403. |
| 23 | POST-redirect-GET on validation failure, where Razor Pages re-renders | Laravel's whole validation story (`old()`, `$errors`, the session error bag) is built on the redirect. Fighting it to return a 200 would mean hand-rolling what the framework already does. Users see the same thing; the tests assert a redirect plus a session error instead of a status code. |
| 24 | Hand-written CSS, no Bootstrap | The .NET app themes Bootstrap 5 with CSS variables. Reproducing that means vendoring 200 kB or depending on a CDN at runtime. The brief asks for minimal styling and an app that runs offline with `artisan serve`, so the dozen primitives the pages use are written out instead. |
| 25 | Ported the security headers middleware from `Program.cs` | Not on the feature list, but the source sends them on every response and dropping them would be a silent downgrade in a project whose point is a faithful comparison. The strict `script-src 'self'` is also why the reader switcher binds its handler in `site.js` rather than with an inline `onchange`. |
| 26 | `errors/500.blade.php` does not extend the layout | The layout renders the reader switcher, which queries the users table. A 500 is often a database failure, and an error page that needs the database is one that fails when it is most needed. |
| 27 | Edit collisions on the unique index are left unhandled | Editing an entry onto a date where the same book is already logged raises a constraint violation and a 500. The source has exactly the same hole. Matching the specification beat improving on it; the gap is pinned by a test and listed in STATUS.md. |

### Added during the phase 2 self-review

| # | Decision | Reasoning |
| --- | --- | --- |
| 28 | `CurrentUser` is not memoised and not bound as a singleton or scoped | Tried, to save three or four reader lookups per request. It holds a `Session`, so any binding outliving a request hands the next request the previous session. `artisan serve` tears the process down between requests and hides it; the test suite runs many requests in one process and failed immediately, showing one reader's library to another. |
| 29 | Query-string values are checked with `is_string`, not cast | `?title[]=x` arrives as an array. Casting that to string is a warning and the literal word "Array". C#'s model binding rejects the shape mismatch for you; PHP hands you whatever arrived. |

## Phase 3: the multi-source lookup

| # | Decision | Reasoning |
| --- | --- | --- |
| 30 | `Http::pool()` for the fan-out, not two sequential calls | The brief calls this the most interesting logic in the app, and doing it sequentially would drop the one property that makes it interesting. A pool hands both requests to curl_multi and blocks until both return, so the wall-clock behaviour matches `Task.WhenAll`. |
| 31 | Clients split into `requestSearch()` and `parseSearch()` | A pool needs every request handed over before any response exists, so a single method that fetches and maps cannot be pooled. This split is the visible cost of PHP not having `await`, and it is the only structural change the port forced on the client classes. |
| 32 | No `CancellationToken` counterpart | Every .NET method here takes one, and the source is careful to let a caller-initiated cancel propagate rather than degrade to empty results. A PHP request has nothing to cancel from, so the parameter has no meaning and was dropped rather than faked. |
| 33 | Kept the provider-failure asymmetry | Open Library throws on a non-success response, Google Books returns empty. It looks like an inconsistency worth tidying. It is inherited from the original Next.js app through the .NET port, and the brief says port like for like. |
| 34 | Config in `config/services.php`, no `IOptions` equivalent | `Options/GoogleBooksOptions.cs` exists because .NET binds configuration to a type. `config()` is already a cached, injectable lookup, so a class per section would be ceremony without a payoff. |
| 35 | `symfony/html-sanitizer` for the description HTML | The .NET version uses Ganss.Xss. Symfony's component is the closest PHP equivalent: allowlist-based, actively maintained, MIT, and nothing to sign up for. |
| 36 | `defaultAction(Block)` on the sanitizer | Ganss.Xss keeps the text of a disallowed tag; Symfony's default is to drop the element and everything inside it. Without this line, a description wrapped in an unknown tag silently became an empty string. Explicit `dropElement` calls are what keep that safe for `script` and `style`. |
| 37 | Live provider tests behind `BOOK_SEARCH_LIVE_TESTS` | Faked responses prove the code does what it is told and nothing about whether the fakes still resemble what the providers send. Three tests assert response shape against the real APIs, off by default so CI never leaves the machine. |

### Added during the phase 3 self-review

| # | Decision | Reasoning |
| --- | --- | --- |
| 38 | The Google API key is redacted before anything is logged | Guzzle puts the full request URL in a connection-failure message, and Google Books only accepts its key in the query string, so a DNS blip wrote the credential into `storage/logs`. .NET's `HttpRequestException` does not carry the URI, so there was nothing in the source to port and nothing to warn me. |
| 39 | `Http::preventStrayRequests()` in the test bootstrap | Wiring search into the log page silently turned the phase 2 page tests into live network calls. They still passed. The only signal was the suite going from 2.8 seconds to 13. |

## Phase 4: documentation

| # | Decision | Reasoning |
| --- | --- | --- |
| 40 | MIGRATION.md names nine specific failures from this run, with how each was caught | The brief asks for an honest account of where AI assistance produced wrong output. A list of categories would have been safer to write and worth nothing to read. |
| 41 | Kept the mapping tables organised by layer rather than alphabetically | A reader coming from readlog-dotnet is looking for "where did my page model go", not for a lookup table. Grouping by tooling, domain, services, HTTP and views follows how you would actually go looking. |
| 42 | STATUS.md leads with what was not done | Everything that was done is visible in the code and the pull requests. What is missing, and why, is the part nobody can reconstruct. |
| 43 | Added the MIT licence, copied from readlog-dotnet | The README claimed MIT before the file existed. Caught in the phase 4 self-review. |
| 44 | Documented the Windows CA-bundle problem in the README | Found by running the live provider tests. A bare PHP install on Windows cannot reach either provider, and because the search tolerates an unreachable provider the only symptom is "No books found." for every query. Nothing in the app suggests a configuration problem. |

## Run 2: local hosting and on-demand exposure

This run changed direction once, and the log keeps both halves so the history is
honest.

It began as "finish deployment support for Laravel Cloud", with a brief that
described an earlier cloud-readiness run as merged. That run is not in the
repository: `main` ended at PR 5 and nothing mentioned Postgres or Laravel Cloud.
The work proceeded anyway (decision 45), and after the database-portability work
was done and before anything was pushed, the author redirected the run: no cloud
provider, no cost, host locally and expose on demand through a Cloudflare Tunnel.
The provider-neutral work was kept because it makes the app runnable anywhere;
the Laravel Cloud-specific material (a `MANUAL-STEPS.md` written against Cloud's
dashboard, and its decisions) was dropped before it reached a pull request.

### Kept from the cloud half: portability

| # | Decision | Reasoning |
| --- | --- | --- |
| 45 | Proceeded on the original cloud brief although the "previous run" it referred to did not exist on `main` | The goal was clear even though the starting point was misdescribed; the missing pieces were exactly what its phase 1 was for. Superseded by the redirection, but it is why the portability work exists. |
| 46 | `lower(title) LIKE lower(pattern)`, both sides lower-cased by the database | The .NET source relies on SQLite's LIKE being ASCII case-insensitive. Postgres LIKE is case-sensitive; ILIKE is Postgres-only. Lower-casing in PHP with `mb_strtolower` was tried first and regressed SQLite for non-ASCII titles, because SQLite's `lower()` is ASCII-only; letting the same SQL function see both strings keeps each database consistent with itself. |
| 47 | Savepoints around the two guarded inserts, via `withSavepointIfNeeded` | Postgres aborts the whole transaction after a constraint violation, so the "confirm it was really a duplicate" re-query fails inside any outer transaction. This is Laravel's own `createOrFirst` pattern; at transaction level zero it does nothing, confirmed against Postgres outside a transaction. |
| 48 | The race test plants the winning row from a one-shot query listener, not a model event | `Book::creating` now fires inside the savepoint and the planted row is rolled back with the loser. A listener on the existence-check SELECT plants it after that query and before the savepoint opens, which is what a concurrent writer's committed row looks like. |
| 49 | Demo seeder dates count back from a fixed anchor, not `today()` | Run on every container start, a `today()`-relative seeder adds fourteen entries per calendar day. A fixed anchor makes re-running it a no-op on any day, at the cost of the demo library slowly looking older. |
| 50 | `DatabaseSeeder` seeds only into an empty catalogue | It is what every start runs. Even an idempotent seeder would resurrect demo rows the author had deliberately deleted. Seed once, then stand aside; the class can still be named explicitly to force it. |
| 51 | Postgres CI job uses only the six documented `DB_*` variables plus `DB_CONNECTION` | The point of the job is to prove the documented contract, not that the app can be made to work with more configuration. `DB_SSLMODE=disable` there because a stock container has no TLS, which is itself the argument for sslmode being a variable. |

### Phase 1: local runtime

| # | Decision | Reasoning |
| --- | --- | --- |
| 52 | Nginx plus PHP-FPM, not `php artisan serve` in a container | The brief allowed either. FPM behind nginx is what the app would sit behind anywhere else, it serves static files without touching PHP, and it costs one small extra container. `artisan serve` is a development server and says so in its own output. |
| 53 | The code is baked into the image; only `storage/` is a volume | A bind mount of the whole checkout from a Windows or macOS host cannot promise the ownership and permissions php-fpm's `www-data` needs for the SQLite file, and it would make the container depend on `vendor/` existing on the host. The image is the artefact; the volume is the state. |
| 54 | `APP_KEY` is generated on first start and kept in the storage volume | Otherwise "one command from a fresh clone" is three, with a paste in the middle. An explicitly provided `APP_KEY` still wins, and the file is `0600` and owned by `www-data`. |
| 55 | Composer installed from getcomposer.org with its published checksum, not `COPY --from=composer:2` | One base image instead of two. Also practical: Docker Hub was only intermittently reachable from the machine this was built on, and every extra image was another chance to fail. |
| 56 | `libpq-dev`, not `postgresql-dev`, for `pdo_pgsql` | Found by the build failing. On current Alpine, `postgresql-dev` drags in an LLVM toolchain for JIT headers this extension never uses; `libpq-dev` is the correct dependency and a fraction of the size. |
| 57 | `php artisan optimize` runs at container start, not at image build | Config is entirely environment-driven and the environment is only known at run time. Caching at build would freeze whatever happened to be set then. Caching at start is safe because the cache lives in `bootstrap/cache` inside the container, not in the volume, and is rebuilt on every start. |
| 58 | Postgres is an override file, not a profile | `-f compose.yaml -f compose.postgres.yaml` changes the app's `DB_*` variables and adds the service in one place, and it needs no environment juggling. A profile would start the database but leave the app pointing at SQLite. |
| 59 | The `web` healthcheck is `/up`, and `/up` is kept off nginx's access log | `/up` is Laravel's own health route and is what the fresh-clone CI job and the smoke check both use. A probe every ten seconds should not be most of the log. |
