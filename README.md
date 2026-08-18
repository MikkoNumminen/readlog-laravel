# ReadLog (Laravel)

A book and reading tracker: search for books across Open Library and Google Books,
log what you finish with a format (book, audiobook or e-book), a finished-on date
and a 0 to 5 star rating, then browse, search, edit and delete your library. There
is an account page with reading stats and a public "recently read" feed.

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
- The [pull requests](https://github.com/MikkoNumminen/readlog-laravel/pulls) carry
  a self-review each, including the bugs found while reviewing.

The original of both apps is
[ReadLog](https://github.com/MikkoNumminen/ReadLog), a Next.js and Prisma
application, so this is the second port of the same behaviour.

## Status

Feature-complete against readlog-dotnet's version 1 scope: books, reading entries,
library search, and the multi-source lookup with its merge logic. 196 passing
tests plus 3 live-API tests that are skipped by default. **There is no
authentication**, deliberately, and the app ships a demo reader switcher in its
place; see STATUS.md for that and for the other known gaps.

## Requirements

- PHP 8.3 or newer, with `pdo_sqlite`, `sqlite3`, `mbstring`, `curl`, `fileinfo`,
  `dom`, `xml` and `zip`
- [Composer](https://getcomposer.org/)

Nothing else. No Node, no build step, no database server, no paid service, no
account to create anywhere.

## Running it

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Then open <http://localhost:8000>. The seed data is two readers, twelve real books
and fourteen reading entries, so every page has something on it immediately.

`composer setup` runs everything except the last line.

## Running the tests

```bash
vendor/bin/pest          # 196 tests, no network access
vendor/bin/pint --test   # formatting
```

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
  Enums/Format.php            # Book / Audiobook / Ebook, with its display strings
  Http/Controllers/           # the Razor page models' counterparts
  Http/Middleware/            # [Authorize]'s counterpart, and the security headers
  Http/Requests/              # validation, which C# keeps as DTO attributes
  Models/                     # Eloquent models
  Services/                   # the reading-log domain and the two book providers
  Support/                    # readonly DTOs
database/
  migrations/                 # hand-written, unlike EF Core's generated ones
  seeders/DemoLibrarySeeder.php
resources/views/              # Blade
public/css/site.css           # one stylesheet, no framework, no build
tests/                        # Pest
```

## Licence

MIT, same as readlog-dotnet.
