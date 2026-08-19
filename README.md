# ReadLog (Laravel)

A book and reading tracker: search for books across Open Library and Google Books,
log what you finish with a format (book, audiobook or e-book), a finished-on date
and a 0 to 5 star rating, then browse, search, edit and delete your library. There
is an account page with reading stats and a public "recently read" feed.

**Live site:** <https://mikkonumminen.dev/readlog-laravel>, a static snapshot of
the seeded library served from the author's portfolio (details under
[Where it runs](#where-it-runs)). The app itself runs on the author's own machine and goes on
a public URL on demand; [DEMO.md](DEMO.md) is how.

This repository is a **documented migration** of
[readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet) (ASP.NET Core 8,
Razor Pages, EF Core) to Laravel 13, done as a learning-in-public exercise by a
C# developer writing PHP for the first time. The .NET app was treated as a
specification: where it does something odd, the odd thing was ported and written
down rather than fixed.

The process is the point, so it is all here:

- **[MIGRATION.md](MIGRATION.md)** is the main document. Building-block mapping
  between the two codebases with file references to both, an honest account of
  what did not translate cleanly, and a section on where AI assistance produced
  wrong output and how each case was caught.
- **[DECISIONS.md](DECISIONS.md)** is a running log of every judgement call, one
  line of reasoning each.
- **[STATUS.md](STATUS.md)** is where the project stands, what each pull request
  contains, and what was deliberately not done.
- **[TODO.md](TODO.md)** is what comes next, recorded rather than built.
- **[DEMO.md](DEMO.md)** is the five-minute version of running it and putting it
  on a public URL for a screen share.
- The [pull requests](https://github.com/MikkoNumminen/readlog-laravel/pulls) carry
  a self-review each, including the bugs found while reviewing.

The original of both apps is
[ReadLog](https://github.com/MikkoNumminen/ReadLog), a Next.js and Prisma
application, so this is the second port of the same behaviour.

## Status

Feature-complete against readlog-dotnet's version 1 scope: books, reading entries,
library search, and the multi-source lookup with its merge logic. 222 passing
tests plus 3 live-API tests that are skipped by default, run against both SQLite
and Postgres in CI. **There is no authentication**, deliberately, and the app
ships a demo reader switcher in its place; see STATUS.md for that and for the
other known gaps.

## Where it runs

**Locally, on the author's machine, exposed on demand.** There is no hosted copy
of this app and no plan to pay for one. It runs from a fresh clone with one
Docker command, and when it needs to be shown to someone it is put on a temporary
public URL through a Cloudflare quick tunnel for the length of the call and taken
down afterwards; [DEMO.md](DEMO.md) is that procedure. Cloud deployment was
looked at and dropped on cost; STATUS.md has the reasoning.

The **.NET version is the one hosted publicly**: readlog-dotnet runs at
<https://readlog-a2feef.azurewebsites.net/> on Azure App Service's free tier.

**A static snapshot of this app is browsable at
<https://mikkonumminen.dev/readlog-laravel>.** It is the seeded demo library,
crawled and saved as plain HTML: the feed, the library in both views, every
book page, every reading entry, the log form and the account page look exactly
as they do in the running app. Nothing on it is live: search, forms, the reader
switcher and the Open Library and Google Books lookup do nothing there, and each
page says so at the top. It is regenerated from this repository with one
command:

```bash
composer snapshot        # php artisan readlog:snapshot -> build/snapshot/
```

and the output is committed into the portfolio site's `public/readlog-laravel/`.

The app itself is not tied to the machine it runs on. The database connection is
entirely environment-driven and the code is tested against a stock Postgres as
well as SQLite, so it runs on any standard PostgreSQL in production; what is
missing is only a place to put it.

## Running it

Two ways. Both end with two readers, twelve real books and fourteen reading
entries already in the library, so every page has something on it immediately.

### With Docker, one command

Needs only Docker (Docker Desktop on Windows or macOS, or the engine plus the
compose plugin on Linux). No PHP on the host, no `.env` file, nothing to paste.

```bash
git clone https://github.com/MikkoNumminen/readlog-laravel.git
cd readlog-laravel
docker compose up --build -d --wait
```

Then open <http://localhost:8080>. The first start generates an application key,
migrates, seeds and caches; every later start finds that already done. The
database is SQLite in a Docker volume, so it survives `docker compose down`.
`docker compose down -v` throws it away.

To run the same app on a stock Postgres 16 instead of SQLite, add the override:

```bash
docker compose -f compose.yaml -f compose.postgres.yaml up --build -d --wait
```

`APP_PORT` (default `8080`), `APP_BIND` (default `127.0.0.1`, so nothing on the
LAN reaches it unless you say `0.0.0.0`) and `GOOGLE_BOOKS_API_KEY` are read from
the shell or from a `.env` file next to `compose.yaml`.

To put the running app on a temporary public URL, `scripts/tunnel-up.sh` (and
`scripts/tunnel-down.sh` to close it). See [DEMO.md](DEMO.md).

### With PHP on the host

- PHP 8.3 or newer, with `pdo_sqlite`, `sqlite3`, `mbstring`, `curl`, `fileinfo`,
  `dom`, `xml` and `zip`
- [Composer](https://getcomposer.org/)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Then open <http://localhost:8000>. `composer setup` runs everything except the
last line.

## Running the tests

```bash
vendor/bin/pest          # 222 tests, no network access
vendor/bin/pint --test   # formatting
```

CI runs the same suite against SQLite and against a stock `postgres:16` service
container, and separately brings the compose stack up from a bare checkout and
probes it, including the forwarded-header behaviour a tunnel relies on.

The suite fakes every outbound HTTP request and fails on any it does not
recognise, so it never leaves the machine. To check the faked provider responses
against reality:

```bash
BOOK_SEARCH_LIVE_TESTS=true vendor/bin/pest --filter=LiveProvider
```

Those three tests assert response shape, never specific data.

## Configuration

Everything has a working default. The two settings worth knowing:

| Setting | What it does |
| --- | --- |
| `GOOGLE_BOOKS_API_KEY` | Enables Google Books. Without it, search falls back to Open Library alone and the book detail page shows no details. Open Library needs no key. |
| `BOOK_SEARCH_LIVE_TESTS` | Lets the tests tagged `live` call the real APIs. Off by default. |
| `DB_CONNECTION` and `DB_*` | `sqlite` by default. Set `pgsql` plus host, port, database, user, password and `DB_SSLMODE` for any standard PostgreSQL; `.env.example` lists them. |
| `TRUSTED_PROXIES` | Which upstream to believe about the original scheme and host. `*` inside compose; `127.0.0.1` for a local `cloudflared` in front of `php artisan serve`. Unset means forwarded headers are ignored. |
| `OLLAMA_URL` and `AI_SEARCH_ENABLED` | Where a local [Ollama](https://ollama.com) answers, for the "ask your library" search. Default `http://localhost:11434` (`host.docker.internal` inside compose), on. Unreachable is fine: the search falls back to title matching and says so. `php artisan readlog:embed` backfills the embeddings for an existing library. |

`php artisan readlog:smoke [--url=...]` checks a running instance: health route,
home page, database, migrations, demo data, providers.

### If search finds nothing on Windows

A bare PHP install on Windows ships no CA bundle, so curl cannot verify TLS and
every provider request fails. Because the search deliberately tolerates a provider
being unreachable, the only symptom is "No books found." for every query.

Download [cacert.pem](https://curl.se/ca/cacert.pem) and point `php.ini` at it:

```ini
curl.cainfo = "C:/path/to/cacert.pem"
openssl.cafile = "C:/path/to/cacert.pem"
```

## Layout

```
app/
  Casts/DateOnly.php          # PHP has no date-only type; this is the stand-in
  Console/Commands/SmokeCheck.php   # php artisan readlog:smoke
  Enums/Format.php            # Book / Audiobook / Ebook, with its display strings
  Http/Controllers/           # the Razor page models' counterparts
  Http/Middleware/            # [Authorize]'s counterpart, and the security headers
  Http/Requests/              # validation, which C# keeps as DTO attributes
  Models/                     # Eloquent models
  Services/                   # the reading-log domain and the two book providers
  Support/                    # readonly DTOs
config/trustedproxy.php       # TRUSTED_PROXIES, for nginx and the tunnel
database/
  migrations/                 # hand-written, unlike EF Core's generated ones
  seeders/DemoLibrarySeeder.php
resources/views/              # Blade
public/css/site.css           # one stylesheet, no framework, no build
tests/                        # Pest
Dockerfile, compose.yaml      # php-fpm + nginx, SQLite in a volume
compose.postgres.yaml         # opt-in stock Postgres 16
docker/                       # entrypoint and nginx config
scripts/tunnel-up.sh, tunnel-down.sh
DEMO.md                       # start, expose, verify, shut down
```

## Licence

MIT, same as readlog-dotnet.
