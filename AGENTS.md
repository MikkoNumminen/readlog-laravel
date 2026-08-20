# AGENTS.md

The contract for a coding agent working in this repository. Read this first; it is
written to be the only file you need before your first change.

`CLAUDE.md` points here. Nothing in this file is specific to one vendor's agent.

## What this repository is

ReadLog: a book and reading tracker. Search Open Library and Google Books, log what
you finished with a format, a date and a 0 to 5 rating, browse and edit your
library, see reading stats and a public feed. One feature beyond that: "ask your
library", a natural-language question answered by a local Ollama over the reader's
own entries.

It is a **documented migration** of
[readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet) (ASP.NET Core 8,
Razor Pages, EF Core) to Laravel 13. That fact changes how you must work here, and
it is the single most important thing on this page:

> **The .NET app is the specification. Where it does something odd, the odd thing
> was ported deliberately and written down. Do not "fix" it.**

Before changing any behaviour, check [DECISIONS.md](DECISIONS.md) for the decision
that put it there. If a decision covers it, the behaviour is intentional and
changing it needs a new decision entry saying so.

## Stack

| | |
| --- | --- |
| Language | PHP 8.4.1 or newer (8.4 in CI). Extensions are declared in `composer.json` |
| Framework | Laravel 13 |
| Views | Blade, one plain stylesheet, no build step, no JS framework |
| Database | SQLite by default, any standard PostgreSQL in production, both tested |
| Tests | Pest 4 |
| Static analysis | PHPStan level 6 via Larastan |
| Formatting | Laravel Pint |
| Local AI | Ollama, entirely optional |

There is no `package.json`, no npm, and no asset pipeline. Do not add one.

## The verification loop

One command decides whether the repository is healthy:

```bash
composer verify
```

It runs, in order: Pint (formatting), PHPStan level 6, the Pest suite, and
`readlog:docs-check` (documentation drift). All four must pass. Measured at 20
seconds with a warm PHPStan cache and under a minute cold, and it reaches no
network at any point.

The individual gates, if you need one alone:

| Command | What it checks | Runtime |
| --- | --- | --- |
| `vendor/bin/pint --test` | Formatting. `vendor/bin/pint` fixes it. | 1 s |
| `composer analyse` | PHPStan level 6. | 20 s cold, 2 s warm |
| `vendor/bin/pest` | The whole suite, no network. | 15 s |
| `php artisan readlog:docs-check` | Documentation against reality. | under 1 s |

Three tests call the real Open Library and Google Books APIs and are skipped unless
`BOOK_SEARCH_LIVE_TESTS=true`. Leave them off.

**A change is not done until `composer verify` is green.** Report the actual
output. Never describe a suite you did not run.

## Getting a working checkout

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

`composer setup` is all four in one. The seed puts 2 readers, 12 books and 14
reading entries in the database, so every page has content immediately.

Then `php artisan serve` for the app on <http://localhost:8000>, or
`docker compose up --build -d --wait` for the full nginx and php-fpm stack on
<http://localhost:8080>.

## The golden path

Every change in this repository takes the same eight steps. If you are unsure what
to do next, you are somewhere in this list.

1. **Search `DECISIONS.md` first.** 128 numbered entries with a topic index at the
   top. If a decision covers the behaviour you are about to change, you are
   changing a decision, not fixing a bug.
2. **Branch.** Never commit to `main`.
3. **Find the recipe.** [docs/RECIPES.md](docs/RECIPES.md) has nine, covering a
   field, a page, a provider, a migration, a config setting, an artisan command,
   changing the AI search, and appending a decision entry.
4. **Check [docs/INVARIANTS.md](docs/INVARIANTS.md)** for anything your change
   touches, particularly under `app/Services/` or `database/migrations/`.
5. **Write the change and its tests together**, in the existing style.
6. **Run `composer verify`.** All four gates must pass.
7. **Update the documentation in the same change**, and append a decision entry for
   anything a reviewer could reasonably have decided differently.
8. **Open a pull request with a self-review**, including what you got wrong first.

### What you may do without asking

- Read anything, run `composer verify` and any single gate, run the app locally.
- Create a branch, and commit to that branch.

### What to ask about first

- Committing or pushing to `main`, and opening or merging a pull request.
- Anything in [Rules](#rules) below that you believe needs an exception.
- Deleting a test, or changing what an invariant asserts.
- Touching `ops/desktop/` or the tunnel scripts, which drive the author's machine.

## Where things go

Read [ARCHITECTURE.md](ARCHITECTURE.md) before placing new code. The short version:

| Kind of code | Where | Rule |
| --- | --- | --- |
| Business rules | `app/Services/` | Takes a user id, never touches the session or the request |
| HTTP handling | `app/Http/Controllers/` | Resolve the user, call a service, return a view. No business rules |
| Validation | `app/Http/Requests/` | Every bound the database cannot enforce lives here |
| Data carriers | `app/Support/` | `final readonly` classes, no behaviour beyond derived values |
| Outbound HTTP | `app/Services/External/` | One class per provider, tolerant of that provider failing |
| Local model calls | `app/Services/Ai/` | Every path must degrade when Ollama is absent |
| Persistence | `app/Models/` | Eloquent only, no queries in controllers |
| Templates | `resources/views/` | No business logic, no inline script tags |

## Rules

These are not preferences. Breaking one breaks a test, a documented decision, or
both.

1. **Never rewrite `DECISIONS.md` history.** Append a numbered row with one line of
   reasoning. The file is a log, not a document to tidy.
2. **Every non-obvious change gets a decision entry.** If a reviewer could
   reasonably have chosen differently, write down why you did not.
3. **The test suite never touches the network.** `Http::preventStrayRequests()` is
   on for every Feature test. If your code makes an outbound request, fake it.
4. **Services take `int $userId`.** Do not reach for `session()` or `auth()` inside
   `app/Services/`. The ownership rules are only testable because of this.
5. **No inline script tags and no `onclick`.** The Content-Security-Policy is
   `script-src 'self'`. JavaScript goes in `public/js/site.js`.
6. **The AI feature is optional forever.** Every path involving Ollama must work,
   degraded and with a visible notice, when Ollama is unreachable. Tests cover this;
   keep them true.
7. **The app runs on SQLite and on PostgreSQL.** No extension-dependent SQL, no
   `pgvector`, no SQLite-only syntax. CI runs the whole suite on both.
8. **Do not add authentication.** Its absence is deliberate and documented in
   [STATUS.md](STATUS.md).
9. **Do not add a JavaScript build step.**
10. **Migrations are hand-written and are not edited once committed.** Add a new one.
11. **Documented facts are checked.** `readlog:docs-check` fails when the docs
    disagree with the routes, commands, files or counts they claim. Update the
    documentation in the same change, not afterwards.

## Task playbooks

[docs/RECIPES.md](docs/RECIPES.md) has nine step-by-step walkthroughs for the
changes this repository actually receives: adding a field to a reading entry, a
page, a book provider, a migration, a configuration setting or an artisan command,
changing the AI search, appending a decision entry, and changing something the docs
describe. Follow the recipe and you land in the same shape a maintainer would have
written.

## What must stay true

[docs/INVARIANTS.md](docs/INVARIANTS.md) lists every invariant with the test that
guards it. Before changing anything under `app/Services/` or
`database/migrations/`, check whether an invariant covers it.

## Documentation map

| File | What it answers |
| --- | --- |
| [ARCHITECTURE.md](ARCHITECTURE.md) | How the system is put together and where new code goes |
| [docs/INVARIANTS.md](docs/INVARIANTS.md) | What must stay true, and which test proves it |
| [docs/RECIPES.md](docs/RECIPES.md) | How to make the common changes, step by step |
| [docs/GLOSSARY.md](docs/GLOSSARY.md) | What the domain words mean in this codebase |
| [docs/AI-FIRST.md](docs/AI-FIRST.md) | How agent-readiness is scored here, and the current score |
| [DECISIONS.md](DECISIONS.md) | Why any given thing is the way it is |
| [MIGRATION.md](MIGRATION.md) | The .NET to Laravel mapping, building block by building block |
| [STATUS.md](STATUS.md) | Where the project stands and what was deliberately not done |
| [TODO.md](TODO.md) | What is planned but not built |
| [DEMO.md](DEMO.md) | Running it and putting it on a public URL |
| [CONTRIBUTING.md](CONTRIBUTING.md) | The change loop, from branch to merged pull request |
| [docs/machine/](docs/machine/) | The same facts as JSON, for tooling that should not parse prose |

## Machine-readable index

`docs/machine/` holds JSON so a script does not have to read English. Seven files,
in two groups.

**Generated.** `php artisan readlog:docs-check --write` rewrites these from the
repository itself, and the check fails when they are stale:

- `routes.json`: every route, verb, name, middleware and controller action
- `commands.json`: every artisan command and every composer script
- `glossary.json`: the domain vocabulary, from `docs/GLOSSARY.md`
- `decisions.json`: all 128 decisions and the topic index, from `DECISIONS.md`
- `test-counts.json`: test files and test blocks, counted statically. The suite's
  own case total is compared against this by CI, after a real run

**Hand written**, and `--write` does not touch them. Edit them yourself and the
check will hold you to it:

- `repo-map.json`: every directory, what it holds, and the rule that governs it
- `invariants.json`: every invariant and the test file that guards it

## Things that will waste your time

- **PHP on Windows finds no books.** A bare PHP install ships no CA bundle, so every
  provider request fails TLS verification, and because the search tolerates a
  provider being unreachable the only symptom is "No books found." for every query.
  Point `curl.cainfo` and `openssl.cafile` at a downloaded `cacert.pem`.
- **`CurrentUser` must not be a singleton or a scoped binding.** It holds a Session,
  so a binding that outlives one request hands the next request the previous
  reader's library. This was tried twice; the comment on `CurrentUser::get()`
  explains it.
- **The first AI question after an idle spell takes about 47 seconds** while Ollama
  loads the model. `php artisan readlog:ask --warm` loads both models up front.
- **`php artisan config:cache` hides later `.env` changes.** `composer test` clears
  the config first for exactly this reason.
- **`RefreshDatabase` runs Feature tests in a rolled-back transaction.** A test that
  needs to observe a real commit has to say so explicitly.
