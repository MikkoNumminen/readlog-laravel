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

### Phase 2: on-demand public exposure

| # | Decision | Reasoning |
| --- | --- | --- |
| 60 | `config/trustedproxy.php` reading `TRUSTED_PROXIES`, no middleware code | Laravel's `TrustProxies` already falls back to `config('trustedproxy.proxies')`. A config key survives `config:cache`, which the container runs at every start; an `env()` call in `bootstrap/app.php` would not. |
| 61 | `TRUSTED_PROXIES=*` inside compose, `127.0.0.1` suggested for `php artisan serve` | In compose the app's only caller is nginx and nginx's only callers are the browser or the tunnel; trusting the caller is what Program.cs does by clearing KnownProxies. On the host, cloudflared is a local process at 127.0.0.1. Nothing is trusted by default. |
| 62 | `SESSION_SECURE_COOKIE` left unset | Null means Symfony sets Secure when the request was HTTPS, which with a trusted proxy is exactly right, and it lets the same instance serve `http://localhost` and `https://x.trycloudflare.com` without a config change between them. |
| 63 | The tunnel is a compose profile, not a required `cloudflared` install | `docker compose --profile tunnel up tunnel` needs nothing beyond Docker and works in PowerShell and sh alike. The scripts are POSIX sh only, run from Git Bash on Windows; a second PowerShell implementation would be one more thing to drift. |
| 64 | Added `readlog:smoke` even though the brief said "if one exists" | DEMO.md's verification step wanted a single command that answers "is it up, from outside, right now", and the tunnel path especially benefits: run from inside the container against the public URL it proves the round trip. Small, tested, provider-neutral. |
| 65 | Missing Google Books key is WARN, not FAIL, in the smoke check | The app degrades to Open Library alone by design, and a demo without a key is a valid demo. The exit code stays zero so a script can gate on it; the table still says so. |
| 66 | Named tunnel documented separately, as a second profile taking a token | The quick tunnel needs no account and is what the brief asks for; a stable hostname needs a free Cloudflare account and a domain, and mixing the two flows would make the quick one look harder than it is. No `--url` on the named service because the hostname-to-service mapping lives in the dashboard. |
| 67 | Could not open a tunnel from this environment; verified everything up to it | The stack was driven with the exact headers Cloudflare's edge adds (confirmed against Cloudflare's own header reference), producing https links and a Secure cookie, and that check is now in CI. DEMO.md ends with the five manual steps that cover the remaining distance, and says which environment could not run them. |

### Phase 3: documentation close-out

| # | Decision | Reasoning |
| --- | --- | --- |
| 68 | No `MANUAL-STEPS.md`; DEMO.md is the procedure | The only remaining manual work is the three-minute tunnel walk-through, and it belongs next to the commands it verifies. A second file would repeat DEMO.md or contradict it. |
| 69 | README says where the app runs and where the .NET one runs, in one paragraph, no placeholder URL | A placeholder invites someone to fill it, and the decision was that there is nothing to fill. The .NET link is real and stays. |
| 70 | STATUS.md gets a "Hosting" section separate from "deliberately not done" | It is both a thing done (local runtime, tunnel, smoke check) and a thing not done (a hosted copy), and the automated-versus-manual split is what a reader coming to run it needs first. |
| 71 | TODO.md lists three hosting options and recommends none, with a question mark | The brief asked for exactly that, and the honest reason is better than a false one: the trade is money against effort against control, and only the author knows which is cheapest for him. |
| 72 | MIGRATION.md gains three mapping rows rather than a new section | The Dockerfile, the startup migration and the forwarded-headers block all have counterparts in readlog-dotnet now, and the mapping table is where a .NET reader looks for them. The narrative sections describe run 1 and stay as they were. |

## Run 3: a static snapshot under the portfolio site

### Phase 1: generate the snapshot

| # | Decision | Reasoning |
| --- | --- | --- |
| 73 | An in-process crawler (`readlog:snapshot`) instead of `wget --mirror` | The brief allowed "wget or an equivalent script". This machine has no wget, and the author is on Windows; an artisan command hands each Request to the HTTP kernel and follows the links each page emits, which is the same crawl with no server to boot and no tool to install, identical on Windows, macOS, Linux and CI. |
| 74 | Directory-per-page with `index.html`, links to clean paths | The portfolio is on Vercel with `cleanUrls` on and `trailingSlash` off, and is previewed with Astro's server; both serve `<base>/library` from `library/index.html`. `.html` links would redirect on one and 404 on the other. |
| 75 | Cover images downloaded into the tree | The portfolio's CSP is `img-src 'self' data:`; the originals on covers.openlibrary.org would be blocked. A failed download is left pointing at the provider and reported, rather than silently substituting nothing. |
| 76 | The snapshot is built from a throwaway seeded SQLite, never the current database | Reproducible from any checkout, and it can never leak whatever the developer's local database holds. The command restores the connection it found when it finishes; the test suite caught it not doing so. |
| 77 | The banner is injected by the generator, in the app's own notice style | The banner describes the snapshot, so it belongs where the snapshot is made, and the pages carry only the app's stylesheet. Phase 2 copies the output; it does not post-process it. |
| 78 | The `<script>` tag is dropped from snapshot pages | The only script auto-submits the reader switcher, which would POST to a static host. Forms stay in the markup, inert; the banner says so. |
| 79 | Only the acting reader's entries are crawled | The app renders one reader at a time and the crawler is one visitor. Ten of the fourteen entries appear; switching reader would need a session per crawl and buys four more edit pages of the same shape. |

### Phase 2 (portfolio repo) and phase 3

| # | Decision | Reasoning |
| --- | --- | --- |
| 80 | A directory under the portfolio's `public/`, since there was no snapshot convention to follow | `/readlog` and `/readlog-net` are Vercel redirects to hosted apps. A committed static tree served by the same Astro build is the closest fit; refreshing it is regenerate and copy. |
| 81 | The portfolio's CV gained "PHP" and "ReadLog, three times" | Its tech-box test requires the CV's Languages row and the box to agree, and the box now claims PHP because the project does. Both edits are factual and flagged in the PR for veto. |
| 82 | No RAG corpus docs for the portfolio's chat backend | They would touch backend doc-count tests and the `verify:backend` chain; out of scope for "ship the snapshot", noted as a follow-up in the PR. |
| 83 | The portfolio PR is not merged by this run | That repository's own rules require the author's explicit per-PR word. The readlog-laravel PRs follow the working mode of the earlier runs and merge on green. |
| 84 | README links the snapshot URL before the portfolio PR merges | The path is fixed by the PR and the URL is deterministic; the link resolves the moment the portfolio deploys, and STATUS.md's ordering makes clear which merge comes first. |

### Review of the snapshot work

| # | Decision | Reasoning |
| --- | --- | --- |
| 85 | The output directory is wiped only if empty or marked as a previous snapshot | `--out=.` resolves to the project root and `deleteDirectory()` would have taken the checkout with it. A `.readlog-snapshot` marker file makes a directory provably ours; anything else is refused with a message. |
| 86 | Per-run state is reset at the top of `handle()` | The console container reuses the command instance across `Artisan::call`s in one process; a second run found the first run's page map, queued nothing, wrote nothing and printed the first run's counts. Found by running it twice; the test that should have caught it asserted only that a stale file was gone, and now asserts the tree came back. |
| 87 | CSRF tokens are stripped from snapshot forms | The forms are inert, and the tokens were the only per-render randomness: with them, no two runs were byte-identical and every refresh of the committed snapshot churned every page. Two runs are now identical, and a test says so. |
| 88 | Cover names fall back to a short hash on collision | Google Books thumbnails all share the path `/books/content`; a name from the path alone would make every one the same file. Latent (the seed uses Open Library only), fixed anyway. |
