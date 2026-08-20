# Contributing

The change loop for this repository, for a person or an agent. Both follow the same
one, which is the point.

If you are an agent, read [AGENTS.md](AGENTS.md) first. This file is the process;
that one is the contract.

## Before you start

This repository is a **documented migration** of an ASP.NET Core app, and the .NET
version is treated as the specification. Behaviour that looks wrong is often a
faithful port of something wrong in the source, kept on purpose and written down.

So the first step of any change is a search, not an edit:

```bash
grep -in "the thing you are about to change" DECISIONS.md
```

If a decision covers it, you are changing a decision rather than fixing a bug. That
is allowed. It needs a new numbered entry saying why the old reasoning no longer
holds.

## The loop

1. **Branch.** Never commit to `main` directly.
2. **Read the recipe.** [docs/RECIPES.md](docs/RECIPES.md) covers adding a field,
   adding a page, adding a provider, adding a migration, and changing the AI search.
   If one fits, follow it.
3. **Check the invariants.** [docs/INVARIANTS.md](docs/INVARIANTS.md) lists what
   must stay true and the test guarding each. Anything under `app/Services/` or
   `database/migrations/` is likely covered by one.
4. **Write the change and its tests together.** A test in the existing style, in the
   existing file if one fits.
5. **Run the gate.**

   ```bash
   composer verify
   ```

   Formatting, PHPStan level 6, the Pest suite, and documentation drift. All four
   must pass. It reaches no network, and takes 20 seconds with a warm PHPStan cache.
6. **Update the documentation in the same change.** Not afterwards. `readlog:docs-check`
   is part of the gate precisely so this cannot be deferred.
7. **Append a decision entry** for anything a reviewer could reasonably have decided
   differently.
8. **Open a pull request** with a self-review in the body, including any bug you
   found while reviewing your own work. Every pull request in this repository
   carries one, and they are the most useful thing in it.

## What a good pull request body has

The pull requests here are part of the product, so they are written rather than
generated. [STATUS.md](STATUS.md) summarises each one, which is a decent sample of
the register.

- What changed, in a sentence.
- Why, if it is not obvious.
- What you verified, with the actual command output. Not "tests pass": the numbers.
- What you found while reviewing yourself, including anything you got wrong first.
- What you deliberately did not do, and why.

## Style

### Code

Pint decides formatting; run `vendor/bin/pint` rather than arguing with it. Beyond
that, match the surrounding code, which has some strong habits:

- **Every class carries a docblock naming its .NET counterpart**, or saying it has
  none. All 48 classes under `app/` do; that line is how a reader maps the two
  codebases, and "none" is itself information, since it marks what this port added.
- **Comments explain why, never what.** The interesting comments in this codebase
  are the ones recording a thing that was tried and was wrong.
- **PHPStan level 6 is not negotiable.** Models need `@property` docblocks because
  the analyser cannot infer attribute types through the casts.

### Prose

Documentation, README text, pull request bodies and any other writing a reader
sees:

- **No em dashes.** Not one appears in any document here, and `readlog:docs-check`
  fails the build if one does. Use a period, a comma, a colon, or parentheses.
- Say the thing directly. No "it is worth noting", no "the real question is".
- Stop when the information stops.
- Be specific. A number beats an adjective, and an honest account of what did not
  work beats a summary that implies everything did.

## Running the tests

```bash
vendor/bin/pest                 # the suite, no network
vendor/bin/pest --filter=Ask    # one area
vendor/bin/pint --test          # formatting
composer analyse                # PHPStan level 6
php artisan readlog:docs-check  # documentation against reality
```

The suite fakes every outbound request and fails on any it does not recognise. To
check the faked provider responses against reality now and then:

```bash
BOOK_SEARCH_LIVE_TESTS=true vendor/bin/pest --filter=LiveProvider
```

Those three tests assert response shape, never specific data.

## Things that will fail the build

| Symptom | Cause |
| --- | --- |
| An unfaked request failure naming a URL | Your code calls out and the test did not fake it |
| PHPStan complaining about an attribute type | A model is missing an `@property` line |
| `readlog:docs-check` naming a missing file | A document links to something you moved or have not written |
| `readlog:docs-check` naming a route | You added a route and did not document it in `ARCHITECTURE.md` |
| `readlog:docs-check` naming an env variable | `config/services.php` reads a key `.env.example` does not document |
| An em dash count | House style. Rewrite the sentence |
| The Postgres CI job alone failing | SQLite-only SQL, or something that depends on a database extension |

## What not to do

The full list is in [AGENTS.md](AGENTS.md#rules). The ones that come up:

- Do not add authentication, npm, or a build step.
- Do not make the Ollama feature required.
- Do not edit a committed migration; add a new one.
- Do not rewrite `DECISIONS.md` history; append to it.
- Do not report a suite you did not run.
