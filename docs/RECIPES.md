# Recipes

Step-by-step walkthroughs for the changes this repository actually receives.
Follow one and you land in the same shape a maintainer would have written.

Every recipe ends the same way, so it is written here once instead of five times:

```bash
composer verify     # pint, phpstan level 6, pest, docs-check. All four must pass.
```

And every recipe that changes behaviour ends with a numbered row appended to
[DECISIONS.md](../DECISIONS.md).

Before starting any of these, read [ARCHITECTURE.md](../ARCHITECTURE.md) for where
things go and [INVARIANTS.md](INVARIANTS.md) for what you may not break.

## Add a field to a reading entry

The most common change, and the one that touches the most layers. Worked example:
adding a `notes` field.

1. **Migration.** New file in `database/migrations/`, never an edit to an existing
   one. Hand-written, with a comment naming what the .NET side does or noting that
   it has no counterpart.

   ```php
   Schema::table('read_entries', function (Blueprint $table) {
       $table->string('notes', 500)->nullable();
   });
   ```

   Nullable, because existing rows have no value. If the column must be non-null,
   give it a default; the app has to migrate a populated database.

2. **Model.** `app/Models/ReadEntry.php`: add the column to `$fillable`, add a cast
   if it needs one, and add an `@property` line. The `@property` line is not
   decoration: PHPStan level 6 infers attribute types from the columns and will be
   wrong without it.

3. **Data carrier.** `app/Support/UpdateReadEntryData.php` and
   `app/Support/LogBookData.php` are `final readonly` classes. Add the property to
   whichever flows carry the field.

4. **Validation.** `app/Http/Requests/UpdateReadEntryRequest.php` and
   `LogBookRequest.php`. Every bound the database cannot enforce belongs here, and
   for this repository that includes bounds the .NET schema enforces with a check
   constraint. Add the rule, add a friendly name in `attributes()` if the field name
   reads badly, and map it in `toData()`.

5. **Service.** `app/Services/ReadLogService.php`. The method already takes
   `int $userId`; keep it that way. If the field is per-reader (almost all are),
   make sure the update path writes it and the shared `books` row is untouched:
   invariant D8 says an edit changes the reader's fields, never the book title.

6. **Read model.** `app/Support/LibraryEntry.php` is what the views actually get.
   Add the field there and wherever the service builds it.

7. **Views.** `resources/views/entries/edit.blade.php` and `log.blade.php` for the
   form control, `library.blade.php` for display. No business logic in a template,
   and no inline script tags: the CSP is `script-src 'self'`.

8. **The AI search.** If the field says anything about the book, add it to the text
   in `app/Services/Ai/EntryEmbedder.php`. Changing that text changes the hash, so
   every entry re-embeds on the next `readlog:embed`, which is the intended
   behaviour and worth saying in the decision entry.

9. **Tests.** At minimum: the service writes and reads it
   (`tests/Feature/Services/ReadLogServiceTest.php`), validation rejects a bad value
   (`tests/Feature/Http/EntryEditTest.php`), and the field survives the edit form
   round trip. Match the existing style: `it('does the thing', function () { ... })`.

10. **Docs.** The field appears in `ARCHITECTURE.md`'s schema table. Run
    `php artisan readlog:docs-check --write` to refresh `docs/machine/`.

## Add a page

1. **Route** in `routes/web.php`. Put it inside the `demo.user` middleware group if
   it acts on behalf of a reader, outside if it is public. Name it; every view links
   by route name, never by literal path, because the app can be served under a path
   prefix.

2. **Controller** in `app/Http/Controllers/`, one action per route. The controller
   resolves the reader with `CurrentUser`, calls a service, and returns a view. No
   queries and no business rules in here.

3. **Service method** if there is any logic at all. It takes `int $userId` first.

4. **View** in `resources/views/`, extending `layouts/app.blade.php`.

5. **Tests** in `tests/Feature/Http/`. Cover: the page renders for its own reader,
   another reader's data is not visible, and an unseeded database redirects home
   rather than erroring.

6. **Docs check** will fail until the route is in `docs/machine/routes.json`. Run
   `php artisan readlog:docs-check --write`.

## Add a book provider

Two exist (`OpenLibraryClient`, `GoogleBooksClient`) and they set the pattern.

1. **Client** in `app/Services/External/`. It must expose the split pair,
   `requestSearch()` and `parseSearch()`, rather than one method that does both.
   That split exists because `Http::pool` needs every request handed over before any
   response exists; it is the visible cost of PHP having no `await`.

2. **Register it** in `BookSearchService`'s constructor and in the pool.

3. **Failure tolerance is mandatory.** Unwrap the response in its own try/catch
   after the pool resolves. Your provider failing must leave the others' results
   intact (invariants F1 through F4).

4. **Redact secrets.** If the provider takes an API key, run failure messages
   through `App\Support\Redact` before logging. Invariant S1.

5. **Ordering matters.** De-duplication keeps the first of a tie, so where you
   concatenate the new provider changes which record wins. Say which position you
   chose and why in the decision entry.

6. **Tests** in `tests/Feature/Services/BookSearchServiceTest.php`: the mapping of
   its fields, a malformed item being skipped rather than fatal, and the provider
   being down while the others answer. Fake the HTTP; the suite reaches no network.

7. **A live test** in `LiveProviderTest.php`, asserting response *shape* and never
   specific data, tagged so it stays skipped unless `BOOK_SEARCH_LIVE_TESTS=true`.

## Add or change a migration

- **Never edit a committed migration.** Add a new one. Someone has already run the
  old one against a database you cannot reach.
- **Both databases.** No `pgvector`, no SQLite-only syntax, no extension-dependent
  types. CI runs the whole suite against stock PostgreSQL 16 and will catch you.
- **No check constraints.** Laravel's schema builder has no API for them and SQLite
  cannot add one to an existing table. The bound goes in request validation instead,
  and the gap gets written down. See decision 10.
- **Write a header comment** naming the .NET counterpart table, or saying there is
  none.
- **Seeding must stay idempotent.** If your migration adds data, seeding twice must
  still be a no-op on both databases (invariant P2), and dates must be anchored to a
  fixed day so seeding tomorrow is also a no-op (P3).

## Change the AI search

Read [ARCHITECTURE.md](../ARCHITECTURE.md#ask-your-library) first. The rule the
feature was built under, before it existed, is in [TODO.md](../TODO.md): three
layers, each allowed to fail downward.

**The containment boundary is not negotiable.** Invariants A1 through A3 say the
model is shown only rows the database already selected, only for the acting reader,
and any id it cites that it was not shown is dropped. A change that lets the model
choose which rows to fetch breaks all three and is a different feature.

- **Adding a filter pattern** (`app/Services/Ai/LibraryQuestion.php`): every pattern
  needs a positive test row and a negative one. The negatives are the point: the
  review that produced decision 104 found "at least 4 books" and "over 3 weeks"
  parsing as ratings, each of which would have silently hidden entries. Silently
  hiding entries is the one failure this parser must not have.
- **Changing the embedded text** (`EntryEmbedder`): the hash covers the text, so
  every entry re-embeds on the next `readlog:embed`. Intended, but say so.
- **Changing the prompt** (`LibraryAsk`): keep the JSON response shape and keep the
  id validation. Unreadable JSON, an empty answer and a timeout must all still land
  on the ranked entries with a notice (invariant F10).
- **Changing timeouts** (`config/services.php`): the current values are measured,
  not guessed, and the reasoning is in the comments next to them and in decision
  103. Measure before you change one.
- **Everything must still work with Ollama off.** Run the suite; `AI_SEARCH_ENABLED`
  is `false` in `phpunit.xml` by default, so most of the suite already proves it.

## Add a configuration setting

Short, and worth its own recipe because the gate enforces the last step.

1. **Read it in `config/services.php`** (or `config/trustedproxy.php`), with a
   comment saying what the value means and, if it is a timeout or a bound, how the
   number was arrived at. The existing ones say "measured", not "seems fine".
2. **Give it a working default.** Everything in this app runs from a fresh clone
   with nothing set, and that must stay true.
3. **Document it in `.env.example`**, commented out if the default is right for
   most people. This is not optional: `readlog:docs-check` fails the build when
   `config/services.php` reads an `env()` key `.env.example` does not mention, and
   it will name the key.
4. **Add it to the README's configuration table** if a reader would ever set it.
5. **Test both states** if the setting changes behaviour, the way the Ollama tests
   cover enabled and disabled.

## Add an artisan command

1. **The class** in `app/Console/Commands/`. It is auto-discovered; nothing needs
   registering. Give the signature per-option descriptions, since `artisan help`
   is the only place a reader sees them.
2. **A docblock** showing the invocations, the way `SmokeCheck` and `EmbedEntries`
   do, and naming the .NET counterpart or saying there is none.
3. **Exit non-zero on failure.** An operator command that always returns 0 cannot
   be used in CI.
4. **Never write to a fixed path under `storage/`.** Name any scratch file for the
   process (`getmypid()` plus randomness) as `readlog:snapshot` does. A fixed path
   makes the checkout shared mutable state and turns the suite flaky for anyone
   running two at once. See decision 121.
5. **Tests** in `tests/Feature/Console/`, faking any HTTP.
6. **Run `php artisan readlog:docs-check --write`** so `docs/machine/commands.json`
   picks up the new command; the check fails until you do.

## Add a decision entry

Append to the last table in [DECISIONS.md](../DECISIONS.md), or start a new
`## Phase` section if you are opening a new run of work.

```
| 112 | What you decided, in one clause | Why, in one or two sentences. Name what you rejected and what it cost. |
```

Never renumber, never rewrite, never tidy an old row. The file is a log.

## Change something the docs describe

`php artisan readlog:docs-check` fails when the documentation disagrees with the
repository. That is the intended behaviour, not an obstacle:

```bash
php artisan readlog:docs-check            # what drifted
php artisan readlog:docs-check --write    # regenerate docs/machine/*.json
```

The generated JSON is refreshed by `--write`. Prose in `ARCHITECTURE.md`,
`README.md` and `docs/` is your job, in the same change as the code.
