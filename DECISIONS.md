# Decisions

Every call made during the migration that a reviewer could reasonably have made
differently, with one line of reasoning each. Appended to as the work proceeds,
newest phase last. The detailed write-up lives in
[MIGRATION.md](MIGRATION.md); this file is the running log.

Source of truth for behaviour: [readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet),
read from a local checkout at `D:\koodaamista\Readlog-csharp`.

## Finding a decision

143 entries is past the size where reading down the file works. Look up the topic,
then search for its numbers.

| Topic | Decisions |
| --- | --- |
| Authentication, and why there is none | 7, 8, 9 |
| Ownership, the demo reader, `CurrentUser` | 8, 9, 28 |
| Rating: the missing check constraint | 10 |
| Dates: `DateOnly`, no `updated_at` | 11, 12, 49 |
| The `Format` enum | 13 |
| Seed data, factories, idempotency | 14, 15, 18, 49, 50 |
| Services, DTOs, no interface layer | 19, 20, 21 |
| Controllers, routing, validation flow | 22, 23, 29 |
| Views, CSS, error pages | 24, 26 |
| Security headers, sanitising, redaction | 25, 35, 36, 38 |
| Edit collisions on the unique index | 27, 91 |
| Concurrency: pools, savepoints, races | 30, 31, 32, 47, 48, 107 |
| Book search: providers, merge, failure tolerance | 30, 31, 33, 34, 46 |
| Tests: live providers, stray requests, race setup | 37, 39, 48 |
| Static analysis and model docblocks | 92, 93 |
| CI, and what each job proves | 16, 51, 92 |
| Docker, compose, nginx, the image | 52 to 59, 105 |
| Proxies, forwarded headers, cookies | 60, 61, 62 |
| The tunnel and public exposure | 63, 66, 67, 89, 90, 97 |
| The smoke check | 64, 65 |
| The static snapshot | 73 to 80, 84 to 88 |
| The portal and path prefixes | 109, 110 |
| The desktop control | 96, 108, 111 |
| AI search: the three layers | 94, 99, 100, 101, 102 |
| AI search: parsing, timeouts, rate limits | 103, 104, 105, 106 |
| Embeddings: storage, staleness, races | 94, 95, 107 |
| Ollama networking | 98, 108 |
| Documentation shape and what it leads with | 40, 41, 42, 68, 69, 70, 72 |
| Agent-facing documentation and the rubric | 112, 113, 120 |
| Documentation drift checking | 114, 115, 116, 118, 119 |
| Prompt injection, recorded not fixed | 117 |
| Test determinism and the environment surface | 121, 122, 125, 129, 130, 131, 133, 137, 138 |
| Measuring and checking the documentation itself | 123, 124, 126, 127, 128, 132, 134, 135, 136 |
| The project-local Laravel audit skill | 140, 141, 142, 143 |

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
| 89 | The tunnel was verified with the native `cloudflared` binary, not the compose profile | Docker Hub was unreachable from the machine at the time (`tls: bad record MAC` on every pull), and DEMO.md already documents the native path as equivalent. The app-side behaviour under test is identical either way; the profile itself is a `docker compose up` of a stock image. |
| 90 | Cloudflare's e-mail obfuscation on `trycloudflare.com` is documented, not worked around | It rewrites the one address on the account page into a "[email protected]" link. Cosmetic, outside the app, and switchable off only on a zone the author owns; a note in DEMO.md is the right amount of effort. |

## Run 4: closing the list

| # | Decision | Reasoning |
| --- | --- | --- |
| 91 | The edit-collision hole is closed rather than kept as a faithful port of the .NET one | Decision 27 kept it because the source has it. The author asked for the list to be closed, a date picker is exactly where a person lands on an occupied day by accident, and the fix is the pattern `logBook` already uses: catch the unique violation inside a savepoint, confirm it is a real collision, raise the same domain exception, show the same message. The pinned-hole test became the fixed-behaviour test. |
| 92 | PHPStan via Larastan at level 6, in CI | Level 6 is where parameter and return types are required everywhere and nullability is reasoned about, which is what `WarningsAsErrors=nullable` bought in .NET. Levels 7 and up argued about union types in ways that produced more annotations than findings for an app this size. |
| 93 | `@property` docblocks on the models, and `@property-read` / `@property-write` where read and write types differ | Larastan infers attribute types from the columns, so `format` was a string and `finished_at` a string; the casts say otherwise and the docblocks are how a static tool learns that. `finished_at` reads as `CarbonImmutable` and accepts a `Y-m-d` string on write, and both are true. |
| 94 | Embeddings live in a plain JSON text column, one row per entry, and the ranking is cosine in PHP | pgvector would need an extension SQLite does not have, and the app has to work on both. A reader's library is hundreds of rows, not millions; 768 floats times a few hundred is well under a millisecond of arithmetic. The `vector` column is read through an accessor that casts to float, because `json_encode` writes `1.0` as `1` under some `serialize_precision` settings. |
| 95 | Writes embed only when the cached probe says Ollama is up, and with a 5 s timeout | Measured: a cold `nomic-embed-text` took over 20 s to answer its first request while another model held the GPU, and 1.6 s warm. A save must not wait for a model load. A missed embedding is filled by the next search or by `readlog:embed`, which uses 120 s for exactly that load. |
| 96 | The desktop control is Windows Python, not a WSL script like `ragctl` | ReadLog's Docker Compose runs from the Windows side and so does the browser and the native `cloudflared.exe`; going through WSL would add a hop for no reason. Stdlib only, so the stock `python.exe` runs it with nothing installed. `on`, `off`, `tunnel on`, `tunnel off`, `status`, `watch`, `doctor`, `open`, `logs`, `smoke`, `embed`; the same board and menu shape as `ragctl` so the two feel like one family. |
| 97 | `tunnel on` prefers the compose profile and falls back to a native `cloudflared.exe` | The profile is what DEMO.md documents and needs nothing on the host, but the image cannot be pulled on this network (decision 89). The native path is the one that works here today; `install.ps1` fetches the binary into `%LOCALAPPDATA%` and the control finds it there or on PATH. Both write the same `.tunnel-url`, and `off` closes an open tunnel first. |
| 98 | `compose.ollama.yaml` joins the app to another project's Docker network when `OLLAMA_DOCKER_NETWORK` is set | The author's Ollama runs inside the portfolio RAG stack's compose project with no published port, so `host.docker.internal` cannot reach it. Same override pattern as `compose.postgres.yaml`; the control adds the file by itself when the variable is in `.env`. |
| 99 | The question is parsed by patterns for format, rating and year, and nothing else | Those three are what a database answers exactly and what a model gets wrong most confidently (it listed a book rated 3 as unrated). Titles and authors go to the embeddings, which handle names well. Every pattern is a phrase a person types and a row in `LibraryQuestionTest`, so a pattern that would misfire is a failing test rather than a silently hidden entry. A bare four-digit number is not a year: "the 1984 one" is a title. |
| 100 | Filters that leave nothing are relaxed, and the model is told so | "E-books about a desert planet" when Dune was an audiobook should say "no e-book, but Dune is an audiobook", not "nothing". The page shows no "Looked at:" line in that case, so the reader can see the constraint was dropped. |
| 101 | The model answers as JSON with the ids it relied on, and ids it was not shown are dropped | That is how "it can only cite what it saw" is enforced rather than hoped for. Unreadable JSON, an empty answer or a timeout all show the ranked entries with a one-line notice; the reader always gets the retrieval layer's result. |
| 102 | The ranked entries the model saw but did not cite stay visible, behind a `<details>` | On "what did I read last year" the first version named one of two matches. The prompt now asks for every fitting entry, and the block makes the next miss visible instead of trusting that it will not happen. |
| 103 | Timeouts: 60 s embed, 90 s generate, 5 s for the embed after a write, 120 s for the backfill, and a warm-up command | Measured on a GPU shared with two other models: 47 s for the first question (chat model loading), one embed timing out at 20 s while models were shuffled, then 0.5 to 4 s. The page bounds let the first question through and still end a stuck one; `readlog:ask --warm` after start-up (the desktop control runs it) is what makes the first real question fast. |
| 104 | Review pass over the AI search: every rating pattern needs a rating word next to the number, and "since" is a lower bound | The review found "at least 4 books", "over 3 weeks", "one or more times" parsed as ratings, "which one starts with" as one star, "since 2022" as exactly 2022, and rating 0 unparseable because a `??` chain treated 0 as nothing. Each would have hidden entries silently, which is the one failure the parser was told to avoid. Nine negative phrases are now test rows next to the positive ones. |
| 105 | nginx waits 240 s on PHP; the app's own bounds do the failing | The app bounds a question at 60 s to embed and 90 s to answer and degrades with a notice; nginx's default 60 s turned a slow first question into a bare 504 before the app could say anything, and PHP kept running behind it. The compose file bind-mounts the config, so a restart of `web` is enough. |
| 106 | Ten questions a minute per address, and the grid/list toggle no longer repeats the question | A question costs seconds of a local model and the app goes on a public URL for demos; php-fpm's default five workers would have stalled every request, `/up` included, under a handful of concurrent asks. The limiter counts only requests that carry `?ask=`. The toggle carried the question and re-ran the model on every click; it now carries only the title search. |
| 107 | A lost race on the embedding's unique key is "already embedded", not a 500 | Two requests can embed the same entry at once (a slow first ask resubmitted in another tab, a save overlapping an ask); the loser hit the unique index and neither the page nor the write path caught it. Caught now and treated as success, since the winner's row is as good. |
| 108 | The desktop control tolerates the Ollama network being absent | `compose.ollama.yaml` declares the other stack's network external, and that stack's `down` removes it, so `on` failed outright when the RAG stack was off. The control now checks the network exists, starts without it when not, and says so on the board; `on` again after the other stack is up re-attaches. Also from the review: `.env` parsed as compose parses it, a committed `.pyc` removed, `install.ps1` skips the download when the Docker image is present, as DEMO.md already said. |
| 109 | The public page serves the live app through a Vercel function, a Tailscale Funnel path mount, and a fallback to the snapshot | The user's requirement: on means the real app at mikkonumminen.dev/readlog-laravel, off means down, and the page must never break. All three funnel ports on the machine are taken by other projects, so readlog gets one path mount (`/readlog-laravel`) on the shared 443 funnel. Measured before building: an unscoped `funnel off` (which the RAG's own control uses) wipes every handler on the port including ours, a scoped `--set-path` add or remove touches only its own path, and the RAG's `--bg 8000` re-assert leaves other paths alone. So the RAG turning itself off degrades this page to the snapshot, never breaks it, and the next readlog `on` re-mounts. |
| 110 | `PortalPrefix` middleware, driven by two validated headers, not by configuration | The funnel strips the mount path, so the app sees `/library` and must still generate links under `https://mikkonumminen.dev/readlog-laravel`. The proxy function announces where the visitor is with `X-Portal-Host` and `X-Portal-Prefix`; the middleware validates both shapes and forces the root URL and scheme for that request only. Spoofing them changes only the sender's own links, the same power `X-Forwarded-Host` already grants, and nothing is cached. Configuration would have hard-coded the portal into the app; headers keep the app deployable anywhere. |
| 111 | ReadLog Control's `on` now means everything, `off` means everything readlog owns | On: start Docker Desktop if needed, `docker start` the existing Ollama container (plain start, never compose, so the other project's configuration cannot change), compose up, warm the models, mount the funnel path, confirm the public page serves this machine. Off: unmount the path (scoped), close any tunnel, compose down. Ollama, Docker Desktop and Tailscale are left running, the same hibernation the RAG control uses. |

## Run 5: making the repository legible to an agent

| # | Decision | Reasoning |
| --- | --- | --- |
| 112 | An "AI-first" score is defined by a written rubric in `docs/AI-FIRST.md`, not asserted | The goal was "this repo's AI-first score must be at least 9", and no such metric exists to appeal to. A rubric with ten named dimensions, a stated definition, and a scoring method is something a reader can disagree with specifically. |
| 113 | `AGENTS.md` is the canonical agent contract and `CLAUDE.md` points at it | Two files with overlapping instructions drift apart, and the one that drifts is the one nobody opens. AGENTS.md is vendor-neutral and carries everything; CLAUDE.md holds only what is specific to running Claude Code here, chiefly that PHP is not on this machine's PATH. |
| 114 | `readlog:docs-check` exists, and it is in `composer verify` rather than only in CI | The command was written after finding README.md claiming 222 tests in one paragraph and 326 in another, with the real number 338, wrong in two files across two pull requests, with nothing to notice. A documentation claim that nothing checks is a claim that will be false. Putting it in the local gate rather than only in CI means the failure arrives while the author still has the context to fix it. |
| 115 | `docs-check` deliberately does not run the test suite | Whatever the suite reported would become the recorded number, and the two would then agree by construction. The statically countable facts (test files, test blocks) are checked here; the suite's own case and assertion totals are compared against `docs/machine/test-counts.json` by a CI step, from outside, against the run that just happened. |
| 116 | Invariants are written as prose with a guarding test named per row, and mirrored as JSON | The enforcement already existed and was unusually good; only the index was missing, so this was transcription rather than engineering. The JSON is what tooling reads, the prose is what a person reads, and `docs-check` fails when they list different ids. What it cannot check is that a test still asserts what its row claims, and it says so rather than implying otherwise. |
| 117 | The prompt-injection weakness in the AI search is documented rather than fixed in this run | `ARCHITECTURE.md` now states plainly that book titles reach the model prompt with no data and instruction fencing, that a successful injection can make the model emit arbitrary prose, and that it still cannot reach data outside the eight entries shown or write anything. Fixing it is a behaviour change to the prompt and deserves its own run with its own adversarial fixture; recording it is what this repository does with known gaps. Added to TODO.md. |
| 118 | Only `config/services.php` and `config/trustedproxy.php` are held to "every env key is documented" | Stock Laravel configuration reads far more environment variables than any application documents, and listing them would bury the seventeen that matter here. The check found all seventeen genuinely undocumented ones, including every Ollama timeout, which were measured values explained in code comments that a reader of `.env.example` could not see. |
| 119 | The em dash count is a build failure, not a style note | Zero appear in any document in this repository, the check costs nothing, and it is the single strongest tell that prose was generated rather than written. A convention that is enforced is a convention; one that is remembered is a preference. |
| 120 | Three graders with different biases score the rubric, and the mean is the score | One model scoring its own repository grades generously and consistently. A strict grader who treats absence as near-zero, a practical grader who credits good prose under an unconventional filename, and a tooling grader who weights what CI actually enforces disagree enough to be worth averaging. Their baseline spread was 4.4 to 5.1 on a repository that felt finished. |

### Found by scoring the repository against the rubric

| # | Decision | Reasoning |
| --- | --- | --- |
| 121 | The snapshot's throwaway database is per-process, not a fixed path | `storage_path('app/snapshot.sqlite')` was created by truncation and deleted in a `finally`, which made the checkout shared mutable state: two suite runs on one working copy, `pest --parallel`, or an IDE watcher, and one run truncates the other's file while the other deletes it. Two independent graders reproduced it and measured the suite red on roughly a third of runs; both correctly called the verification loop non-deterministic, which is the one property it cannot lack. Now `snapshot-<pid>-<random>.sqlite`, assigned before the `try` so the `finally` always has a path. Two concurrent full suites now pass. |
| 122 | `composer.json` says `^8.4.1`, not `^8.3`, and declares its extensions | The claim of 8.3 was false: 17 locked Symfony 8 packages require 8.4.1, so a fresh clone on 8.3 fails at `composer install`. Nobody had run that combination. The extension list existed only inside the CI workflow and a Dockerfile comment, so a missing `ext-dom` surfaced as a confusing failure much later instead of at install. `pdo_sqlite` and `pdo_pgsql` are suggested rather than required, because the Postgres CI job installs only one of them. |
| 123 | `docs-check` checks counted claims and link anchors | Two documents said the decision log held 111 and 115 entries when it held 120, and the checker printed "Documentation matches the repository." A count is the easiest claim to check and the easiest to leave behind. Anchors were being stripped before the path was resolved, so a link to a renamed heading resolved happily and landed the reader at the top of the file. Both checks were written by first reproducing the miss. |
| 124 | The score in `docs/AI-FIRST.md` is the measured one, including the dimensions that are not 10 | The first draft of that file carried invented per-dimension numbers written before anything was measured. The recorded score is now the mean of three graders who ran the gate themselves, and the gaps they found that are still open are listed rather than removed. |

### Found by the second scoring round

| # | Decision | Reasoning |
| --- | --- | --- |
| 125 | The second flake in `SnapshotTest` is fixed by asserting on "answered 405", not on "405" | `snapshotDir()` embeds `getmypid()` and the command prints that path back, so `doesntExpectOutputToContain('405')` failed for any pest process whose pid happened to contain 405. A grader hit it on their first run and worked out the mechanism; roughly one run in two hundred. The same class of bug as decision 121 and in the same file: a test coupled to something that varies per process. The command's real message is "{url} answered 405; skipped", so matching that phrase keeps the assertion's meaning and drops the coincidence. |
| 126 | The CI test-count step reads the first `<testsuite>`, not the `<testsuites>` root | It was written to read the root's `tests` attribute, and PHPUnit does not put one there, so the step would have compared `None` against 347 and failed every run. It had never executed, which is exactly how a check written and not run goes wrong. Verified now by running the suite with `--log-junit` and executing the comparison by hand. |
| 127 | All 48 classes under `app/` name a .NET counterpart or say they have none | CONTRIBUTING.md asserted this as an existing convention and nine files did not follow it, mostly the AI search and the snapshot command. Adding the line was cheaper than weakening the claim, and "none" carries real information here, because it marks precisely what this port added rather than ported. |
| 128 | `docs-check` reads counts written as words as well as digits | The recipe count is written "nine", and the first version of the check only matched digits, so it passed a deliberately wrong "twelve" without complaint, while appearing to cover every count in the documentation. |
| 129 | Only the extensions the runtime actually needs are required; `ext-zip` is a suggestion | Declaring `ext-zip` broke the Docker build on the first CI run of this branch: `php:8.4-fpm-alpine` has no zip extension and `composer install --no-dev` refused. No production package in the lockfile requires it; Composer wants it for itself, and the Dockerfile already installs the `unzip` binary for that. The required list is now `curl`, `dom`, `fileinfo`, `mbstring`, `pdo` and `xml`, every one of which the base image has. The failure was the new declaration working correctly: it named a mismatch between what the app claimed to need and what its own production image provides. |
| 130 | The snapshot cleanup test asserts the difference before and after, not the old fixed path | It still checked `storage/app/snapshot.sqlite`, which decision 121 stopped creating, so it passed even with the `finally` that deletes the file removed outright. Asserting the directory is empty instead would fail on a file some killed run left behind, which is a real state and not this test's subject. The set difference is the only form that catches the regression without inventing one. Verified both ways by deleting the cleanup and watching it go red. |
| 131 | Stale throwaway databases are swept at start-up | The per-process name traded away the fixed path's one virtue: it was truncated on every run, so a leftover was reclaimed by the next. A kill, a fatal outside the try, or a cancelled CI job now leaks a few hundred KB that `storage/app/.gitignore` hides from `git status`. One was found on this machine during the review. Anything older than an hour is past the longest crawl and short of anything a concurrent run needs. |
| 132 | CI runs `composer verify` and nothing it already contains | The composite step was added on top of the four open-coded steps rather than in place of them, so every push ran Pint, PHPStan, the suite and docs-check twice, and a flaky test got two chances to redden the build. The suite runs a second time only for the JUnit file the count comparison needs. |
| 133 | `config.platform` is removed, and `ext-pdo_sqlite` is required | Pinning the platform tells Composer to stop verifying the real PHP version, which reopened from the other side the hole `^8.4.1` was meant to close: `composer install` would succeed on 8.3 and fail later in `platform_check.php`. Separately, `ext-pdo` was required but not `ext-pdo_sqlite`, so the one extension a default clone cannot start without was the one not named at install. The Postgres CI job installs it now too, since composer install refuses without it. |
| 134 | The documentation checks derive their file list and reproduce GitHub's slug rules | Three places defined "the documentation" (a hardcoded list in the command, `entryPoints` in repo-map.json, a glob in the test) and a new root `.md` file would have escaped the command's checks entirely. The anchor check stripped underscores and ignored the `-1` suffix GitHub appends to repeated headings, so correct links were reported broken. The link pattern also skipped any link carrying a title, and accepted a directory as a valid target. |
| 135 | Number words are matched with boundaries, and the invariant check runs both ways | Without a boundary, "fifty-nine" matched the `nine` alternative and reported a false failure against 59, and every count this repository states as a word is above the twenty-word ceiling. The invariant check only verified JSON to prose, while the edit a person makes is to add a row to the prose and forget the hand-written JSON. |
| 136 | The `docs-check` test reads the command's stock-key list instead of copying it, and the tests do not mutate the repository | The copied list was the concrete drift the review named: remove a name from the const and the test keeps skipping a key the command now demands. Exposing the const removes that. The first attempt at the wider fix had the tests break a real file and restore it in a `finally`, which reads well and is wrong here: two suites running at once is a property this repository claims and tests for, and a mutation is visible to the other run. It corrupted `config/services.php` and truncated `STATUS.md` to two lines on the first concurrent execution. Testing that the checks fire needs the command to accept a fixture root, which it does not; until then each check was verified by hand, breaking and restoring one at a time. Recorded in TODO.md. |
| 137 | The stale-database sweep tolerates the file vanishing under it | `File::glob()` then `File::lastModified()` is a time-of-check gap, and with three suites running the other process's `finally` closed it first: `filemtime(): stat failed`. The file being gone is the outcome the sweep wanted, so the stat is wrapped and a failure means someone else got there. Found by running the suite three times at once, which is the check that also found decision 136's mistake. |
| 138 | The cleanup test asserts on this process's own file, not on the directory | Three forms were tried. The old fixed path passed with the cleanup deleted. An empty-directory assertion failed on a file a killed run had left. A before-and-after difference failed on another concurrent suite's snapshot still in flight. Globbing `snapshot-<own pid>-*` is the only one that is both sensitive to the regression and blind to every other process. |
| 139 | `ARCHITECTURE.md` describes `X-ReadLog-App` alongside the security headers, and says it is not one | PR 23 added the header to `SecurityHeaders` while PR 24 was open, so the merge of the two left the middleware setting a header the architecture document did not mention. `docs-check` cannot catch this: it verifies routes, paths, counts and links, not the contents of a class. Found by reading the merge rather than by the gate, which is the honest limit of what the drift checker covers. |

## Run 6: a Laravel-shaped audit for a Laravel codebase

| # | Decision | Reasoning |
| --- | --- | --- |
| 140 | A project-local `mikko-laravel-audit` skill exists, because the installed `mikko-*` suite is blind to PHP | The suite's detector fingerprints package.json, tsconfig, pyproject, Cargo.toml, go.mod and csproj files and never opens composer.json, so it reported "language: unknown, security surface: low" for this repo; the only stack-specific audits installed are .NET and React, and both bail at their own pre-flights here. The skill mirrors the dotnet audit's proven shape: pre-flight fit check, deterministic grep candidates gating five parallel judges, every check pairing a smell with a legitimate counter-example. Two local adaptations: DECISIONS.md immunises documented oddities, and the report format must itself pass `readlog:docs-check`, since this repository audits its own documentation. |
| 141 | The first audit run kept zero of 121 candidates, and the report says why a zero is credible here | Every candidate matched a documented counter-example, most of them this repo's own recorded past bugs; the judges cite the exact code they cleared rather than waving groups through, and four earlier review passes had already fixed precisely these shapes. The run also caught a bug in its own pre-pass: five `git grep` patterns beginning with `->` were parsed as command options, silently zeroing six checks (one seed serves both B1 and B2), caught because a zero on `$request->query(` was impossible. Recorded in the skill's failure modes: a zero from a tool is a claim to verify, not a fact. |
| 142 | The skill gains an opt-in `--prs` phase: findings become area-named branches, each PR machine-reviewed, review findings fixed before handover | The read-only default stays, and a zero-finding run lands nothing. The phase exists because the audit loop in this repository has never actually ended at a defect list: every review pass so far (PRs 9, 12, 20, 24) ended in a reviewed PR whose review found more than the pass itself did, twice including bugs in that pass's own fixes. Naming that ending in the procedure makes it happen by rule rather than by memory. Merging stays human, always. |
| 143 | The audit's deterministic phases ship as a bundled stdlib script, not as prose the model re-executes | Phases 0 and 1.5 need no judgement, yet the model was re-composing ~20 grep invocations per run, which is neither deterministic nor cheap, and the first run proved it by mis-quoting its own greps. `laravel-audit.py` does the fit check and every seed grep in one call, invoking git grep as an argument vector with `-e` and `--` so the dash-leading-pattern bug class cannot exist, and reporting a failed grep as an ERROR line on that check instead of a silent zero. The script's seed dict is the executable source of truth; SKILL.md's table documents it and serves as the python-less fallback, and the freshness check pins marker seeds in both so drift is caught. Same token-economy standard the other mikko skills hold themselves to. |

