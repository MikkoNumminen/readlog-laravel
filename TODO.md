# TODO

Recorded, not implemented, apart from the first section, which records what
was built and what of it is left.

## AI-assisted natural-language search: done

Built in PRs 16, 18 and 19 as "ask your library"; README.md describes it and
DECISIONS.md #94 to #108 record the choices. It kept the shape this file asked
for: a deterministic layer in front of the model, embeddings over the entries
in a plain table with cosine in PHP, a small local model that only phrases what
was retrieved and can only cite what it was shown, and a fallback to the title
search with a one-line notice when Ollama is absent.

What is left of it, recorded rather than built:

- **Descriptions in the embeddings.** An entry is embedded from title, author,
  format, rating and dates. That is enough for "the one about a desert planet"
  only because the model has heard of Dune. Storing the Google Books description
  on the book (it is fetched for the detail page and thrown away) and embedding
  it would let "a man alone in space" find Project Hail Mary. Cheap, and the next
  thing to do here.
- **Relative dates finer than a year.** "Last summer", "this spring", "in
  March" are not parsed; the embedding sees "Finished on June 5, 2026" and often
  gets it right anyway. A parser for seasons and months is thirty lines and a
  dozen test rows.
- **A streaming answer.** The page waits for the whole answer. Fine at 0.5 to 4
  seconds warm; a first cold question can take half a minute, which the warm-up
  covers but a streamed response would show.

## AI candidates considered and parked

**Recommendations from rating history.** "You gave these five stars, here are three
you have not logged." Parked because the interesting version needs a corpus this
app does not have. With one reader's few dozen ratings, the honest implementations
are content similarity over what Open Library and Google Books already return,
which is not much of an AI feature, or a model guessing from titles, which is a
recommendation engine with no evidence behind it. Worth revisiting if the
catalogue ever holds many readers' entries.

**LLM-assisted merge of conflicting multi-source metadata.** The natural next step
for `BookSearchService::deduplicate()`. Today, when Open Library and Google Books
disagree about the same book, the winner is whichever record has more non-null
cover and page-count fields, and everything the loser knew is discarded. There is
a test that spells this out
(`tests/Feature/Services/BookSearchServiceTest.php`, "merges conflicting metadata
by richness, not by correctness"): in that case 1965 was almost certainly the
better first-publish year and it is replaced by 1990.

A model could do field-level merging with a reason for each choice. Parked for
now for two reasons. The deterministic version has to come first: a field-level
merge with sensible rules (prefer the earlier plausible year, prefer the longer
title only when it contains the shorter one, prefer a page count within a sane
range) would fix most of it with no model at all. And this runs on every search
result, so it is the worst possible place to add a per-item model call. If it
happens, it happens behind a cache and only for the handful of records that
actually conflict.

## Infrastructure

**Docker Compose and the Cloudflare Tunnel: done.** Both were listed here as
questions after run 1 and were built in run 2 (PRs 6 and 7). `docker compose up
--build -d --wait` runs the app from a fresh clone; `scripts/tunnel-up.sh` puts it
on a temporary public URL. DEMO.md has the procedure. The one prediction made
here in run 1 that turned out wrong: it said `APP_URL` would need to match the
tunnel host. It does not; with a trusted proxy the app uses the request's own
forwarded scheme and host, and `APP_URL` only matters outside a request.

**Hosting?** Open. The app currently runs on the author's machine and is exposed
on demand, which is deliberate: zero cost, no third-party account holding a copy,
and a demo is the only audience it has had. Cloud deployment was started in run 2
and dropped for that reason (STATUS.md, "Hosting"). The portability work stayed,
so if a permanent public copy is ever wanted the app is ready for it: env-driven
configuration, tested on SQLite and on stock Postgres, a container that starts
clean, `/up`, and `readlog:smoke` to check it once it is up. What is not decided,
and is not being decided here, is where. The options that exist:

- A free-tier shared PHP host. Zero cost, usually MySQL rather than Postgres
  (which this app has never been tested on; SQLite on a shared host is
  workable if the file lives on persistent disk), no Docker, deployment by
  git or FTP, and terms that vary.
- A self-installed VPS. A few euros a month, full control, `docker compose up`
  is the whole deployment, TLS via the named-tunnel option already in
  `compose.yaml` or via a reverse proxy, and it is the author's to patch and
  keep running.
- A paid PaaS (Laravel Cloud, Fly.io, Railway, Render, and the like). Least
  effort per deploy, a managed Postgres, a real bill every month, and an account
  and a repo connection to maintain. The Laravel Cloud path was drafted in run 2
  and would take an afternoon to redo from the current state.

None of these is recommended over the others. The trade is money against effort
against control, and only the author knows which of those is cheapest for him.
The question mark stays until there is a reason to remove it.

## Correctness and tooling

**Authentication.** readlog-dotnet has ASP.NET Core Identity with local accounts
and an optional Google login. Version 1 of this port has none, deliberately, and
ships `app/Services/CurrentUser.php` as a session-backed demo reader switcher in
its place. Per-user ownership is fully modelled and tested, so this is wiring
rather than design: `CurrentUser::get()` becomes `auth()->user()`, the
`demo.user` middleware becomes `auth`, `actingAsReader()` in `tests/Pest.php`
becomes `actingAs()`, and the switcher is deleted. Laravel Fortify or a
hand-rolled session login would both do it without adding npm, which Laravel
Breeze would. The users table already carries a `password` column for this.

**The rating check constraint.** The .NET schema bounds `rating` to 0 to 5 at the
database level with `CK_ReadEntry_Rating`. Laravel's schema builder cannot express
a check constraint and SQLite cannot add one to an existing table, so the bound
lives only in request validation. A SQLite trigger, or a raw `CREATE TABLE` in the
migration, would restore it. The test
`tests/Feature/Database/SchemaConstraintsTest.php` currently asserts the gap, so
it is the test that should fail and be rewritten when this is done.

**A test for the embedding race.** `EntryEmbedder::embedMany()` catches
`UniqueConstraintViolationException` and treats a lost race as success, because the
winner's row is as good as ours (decision 107). Nothing pins that. The one-shot
query-listener technique that pins the catalogue-book race in `ReadLogServiceTest`
does not reach the window here: the only query the embedder issues against
`read_entry_embeddings` is an eager load with an `IN` list, so a row planted from
the listener is found by `updateOrCreate`'s lookup and updated rather than
colliding. Forcing it needs either a second real connection, or splitting the
`updateOrCreate` into an explicit `firstOrNew` and `save()` so the lookup is a
separate, hookable query. The second is small and would make the catch testable
without changing behaviour.

**A red-team fixture for the AI search.** The containment boundary holds where it
matters: the model is shown only rows the database already selected for the acting
reader, and any id it cites that it was not shown is dropped and could not have
fetched a row anyway. There is a test for both. What is not bounded is the shape of
the prompt itself: book titles and authors are reader-supplied text, and the entries
block is delimited only by a header line and newlines, so a title containing a
newline followed by text that mimics the trailing instruction block is structurally
indistinguishable from the prompt's own framing. A successful injection cannot reach
data outside the entries already shown and cannot write anything, but it can make
the model emit arbitrary prose to the reader or cite the wrong subset. Two pieces of
work: fence the entries block so data cannot be mistaken for instructions, and add
`tests/Feature/Ai/LibraryAskInjectionTest.php` with adversarial titles as fixtures,
asserting the answer stays grounded and the citation allow-list still holds. The
second is worth doing even before the first, because it turns an argument about
whether the prompt is safe into a test that either passes or does not. Recorded in
decision 117.

**A fixture root for `readlog:docs-check`.** Every check in the command resolves
against `base_path()`, so the only way to prove a check actually fires is to break
a real file in the working tree. That is not safe here: two suites running at once
is a property this repository claims, tests for and documents, and a mutation is
visible to the other run. An attempt at it corrupted `config/services.php` and
truncated `STATUS.md` on the first concurrent execution (decision 136). Threading
an optional root through the command, defaulting to `base_path()`, would let the
tests point it at a temporary tree and assert each failure message without touching
anything shared. The route, command and count checks read global application state
and would stay as they are. Until then the checks are verified by hand, one at a
time, which is how every one of them was confirmed to work.

