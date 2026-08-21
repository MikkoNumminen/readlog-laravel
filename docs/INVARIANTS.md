# Invariants

What must stay true in this codebase, and the test that proves each one.

This file exists so that an agent changing code can find out, without reading the
whole test suite, whether it is about to break something load-bearing. Every row
names a real test. If you change behaviour a row describes, you are changing a
decision, not fixing a bug: say so in [DECISIONS.md](../DECISIONS.md).

`php artisan readlog:docs-check` verifies that every test file named below exists.
It cannot verify that the test still asserts what the row claims, so when you
delete or rewrite a test, update its row in the same change.

The machine-readable form of this file is
[docs/machine/invariants.json](machine/invariants.json).

## Ownership

The app has no authentication, so every ownership rule is enforced in the service
layer against an explicit user id. These are the invariants that would become
security bugs the day authentication lands.

| # | Invariant | Guarded by |
| --- | --- | --- |
| O1 | A reader's library contains only that reader's entries | `tests/Feature/Services/ReadLogServiceTest.php` |
| O2 | Updating another reader's entry is refused and leaves it untouched | `tests/Feature/Services/ReadLogServiceTest.php` |
| O3 | Deleting another reader's entry is refused | `tests/Feature/Services/ReadLogServiceTest.php` |
| O4 | Another reader's entry answers 404, never 403, so its existence does not leak | `tests/Feature/Http/EntryEditTest.php` |
| O5 | Account statistics count one reader's entries only | `tests/Feature/Services/ReadLogServiceTest.php` |
| O6 | The "have I read this?" lookup matches the acting reader's titles only | `tests/Feature/Services/ReadLogServiceTest.php` |
| O7 | The AI search never shows a reader another reader's shelf | `tests/Feature/Ai/LibraryAskTest.php` |
| O8 | The public feed projection exposes no user fields at all | `tests/Feature/Services/ReadLogServiceTest.php` |

**Rule that keeps these true:** every method in `app/Services/` takes `int $userId`
as an argument and never reads the session. Controllers resolve the reader and pass
it in. Do not move that resolution into the service layer.

## Data integrity

| # | Invariant | Guarded by |
| --- | --- | --- |
| D1 | One reader cannot log the same book twice on the same date | `tests/Feature/Database/SchemaConstraintsTest.php` |
| D2 | The same reader can log the same book on a different date | `tests/Feature/Database/SchemaConstraintsTest.php` |
| D3 | Two readers can log the same catalogue book on the same date | `tests/Feature/Database/SchemaConstraintsTest.php` |
| D4 | `open_library_id` is unique where present, and many books may have none | `tests/Feature/Database/SchemaConstraintsTest.php` |
| D5 | Deleting a reader deletes their entries | `tests/Feature/Database/SchemaConstraintsTest.php` |
| D6 | A book that entries still point at cannot be deleted | `tests/Feature/Database/SchemaConstraintsTest.php` |
| D7 | Deleting a reading entry keeps the catalogue book | `tests/Feature/Http/EntryEditTest.php` |
| D8 | Editing an entry changes the reader's fields, never the shared book title | `tests/Feature/Services/ReadLogServiceTest.php` |
| D9 | An edit colliding with an existing entry on the same date is refused with a message | `tests/Feature/Http/EntryEditTest.php` |
| D10 | Losing the race to create a shared catalogue book recovers instead of failing | `tests/Feature/Services/ReadLogServiceTest.php` |
| D12 | Rating 0 is a real rating and is not the same as unrated | `tests/Feature/Models/ReadEntryTest.php` |

### Enforced in code, not yet pinned by a test

**Losing the race on an embedding's unique key counts as already embedded.** Two
requests can embed the same entry at once (a slow first ask resubmitted in another
tab, a save overlapping an ask); the loser hits the unique index on
`read_entry_id`, and `EntryEmbedder::embedMany()` catches
`UniqueConstraintViolationException` and treats it as success, because the winner's
row is as good. See decision 107.

There is no test for it. The collision has to happen between `updateOrCreate`'s
lookup and its insert, and the one-shot query-listener technique that pins the
catalogue-book race in `ReadLogServiceTest` does not reach that window here: the
only query the embedder issues against the table is an eager load with an `IN`
list, so a row planted from the listener is found by the lookup and updated rather
than colliding. A test that does not actually force the collision would assert
nothing while looking like coverage, so there is none. Recorded in
[TODO.md](../TODO.md).

### One deliberate gap

The .NET schema has a check constraint, `CK_ReadEntry_Rating`, bounding the rating
to 0 through 5. Laravel's schema builder has no check-constraint API and SQLite
cannot add one to an existing table, so **the bound is enforced in request
validation and in the model, not in the database.** That is a real loss of a
database-level guarantee. It is pinned by a test that asserts the gap exists
(`tests/Feature/Database/SchemaConstraintsTest.php`) so that closing it later is a
deliberate act. See decision 10.

## Failure tolerance

The app is built so that everything outside it is allowed to be missing. These
invariants are what "degrades gracefully" means in concrete terms.

| # | Invariant | Guarded by |
| --- | --- | --- |
| F1 | One book provider failing still returns the other's results | `tests/Feature/Services/BookSearchServiceTest.php` |
| F2 | Both providers failing returns an empty list, not an error | `tests/Feature/Services/BookSearchServiceTest.php` |
| F3 | A connection failure is tolerated, not only an error status code | `tests/Feature/Services/BookSearchServiceTest.php` |
| F4 | A malformed item from a provider is skipped, not fatal to the whole response | `tests/Feature/Services/BookSearchServiceTest.php` |
| F5 | With no Google Books key, Google is skipped entirely and no request is made | `tests/Feature/Services/BookSearchServiceTest.php` |
| F6 | An unreachable Ollama makes the ask box fall back to title matching, with a notice | `tests/Feature/Http/LibraryAskPageTest.php` |
| F7 | A save succeeds even when Ollama dies mid-write, leaving the embedding gap to fill later | `tests/Feature/Ai/EntryEmbedderTest.php` |
| F8 | An unreachable Ollama is probed once, then left alone until the cache expires | `tests/Feature/Ai/EntryEmbedderTest.php` |
| F9 | AI search switched off never touches the network | `tests/Feature/Ai/OllamaClientTest.php` |
| F10 | A model that answers with nonsense still shows the ranked entries plus a notice | `tests/Feature/Ai/LibraryAskTest.php` |

## The AI containment boundary

The library question is untrusted text that reaches a language model. These are the
bounds on what the model is allowed to do with it. **Treat this table as
security-relevant: it is the difference between a model that reports and a model
that decides.**

| # | Invariant | Guarded by |
| --- | --- | --- |
| A1 | The model is shown only entries that already passed the deterministic filters | `tests/Feature/Ai/LibraryAskTest.php` |
| A2 | Ids the model was not shown are dropped from its citations | `tests/Feature/Ai/LibraryAskTest.php` |
| A3 | The model is only ever shown the acting reader's entries | `tests/Feature/Ai/LibraryAskTest.php` |
| A4 | An empty library is answered without calling the model at all | `tests/Feature/Ai/LibraryAskTest.php` |
| A5 | An empty question never reaches Ollama | `tests/Feature/Http/LibraryAskPageTest.php` |
| A6 | The question length is capped before it reaches Ollama | `tests/Feature/Http/LibraryAskPageTest.php` |
| A7 | Filters that match nothing are relaxed, and the page shows that they were | `tests/Feature/Ai/LibraryAskTest.php` |
| A8 | Entries the model saw but did not cite stay visible, so a miss is visible | `tests/Feature/Ai/LibraryAskTest.php` |
| A9 | Questions are rate limited per address; plain library visits are not | `tests/Feature/Http/LibraryAskPageTest.php` |
| A10 | The grid and list toggle does not carry the question, so it does not re-ask the model | `tests/Feature/Http/LibraryAskPageTest.php` |

The model's authority is bounded to **phrasing an answer over rows the database
already selected**. It cannot widen the result set, cannot reach another reader's
data, and cannot cite anything it was not handed. Any change that lets the model
choose which rows to fetch breaks A1 through A3 and needs a decision entry.

## Portability

| # | Invariant | Guarded by |
| --- | --- | --- |
| P1 | The whole suite passes on SQLite and on stock PostgreSQL 16 | `.github/workflows/ci.yml`, the `postgres` job |
| P2 | Seeding twice is a no-op on both databases | `tests/Feature/Database/DemoLibrarySeederTest.php`, and the CI Postgres job |
| P3 | Seeding is anchored to a fixed day, so seeding on a later day is still a no-op | `tests/Feature/Database/DemoLibrarySeederTest.php` |
| P4 | A non-ASCII title typed exactly as written matches on every database | `tests/Feature/Services/ReadLogServiceTest.php` |
| P5 | Nothing but PHP objects goes into the cache, so a serialising store works | `tests/Feature/Services/PublicFeedCacheTest.php` |
| P6 | A stored embedding round-trips through a plain JSON text column as floats | `tests/Feature/Ai/EntryEmbedderTest.php` |

## Privacy and secrets

| # | Invariant | Guarded by |
| --- | --- | --- |
| S1 | The Google Books API key never appears in a log line | `tests/Feature/Services/BookSearchServiceTest.php` |
| S2 | The smoke check never prints an API key that appears in an error message | `tests/Feature/Console/SmokeCheckTest.php` |
| S3 | A provider failure still logs enough to diagnose it | `tests/Feature/Services/BookSearchServiceTest.php` |
| S4 | The test suite makes no unfaked outbound request | `tests/Pest.php`, via `Http::preventStrayRequests()` |
| S5 | A book description is sanitised before it is rendered | `tests/Feature/Http/BookSearchPagesTest.php` |
| S6 | An info link that is not http or https is refused | `tests/Feature/Http/BookSearchPagesTest.php` |
| S7 | Security headers, including the strict CSP, are on every response | `tests/Feature/Http/SecurityHeadersTest.php` |

## Deployment behaviour

| # | Invariant | Guarded by |
| --- | --- | --- |
| E1 | Behind a trusted proxy, generated links use the forwarded scheme and host | `tests/Feature/Http/BehindProxyTest.php` |
| E2 | Behind https, the session cookie is marked Secure | `tests/Feature/Http/BehindProxyTest.php` |
| E3 | With no trusted proxy configured, forwarded headers are ignored | `tests/Feature/Http/BehindProxyTest.php` |
| E4 | The portal prefix headers are validated for shape, never trusted blindly | `tests/Feature/Http/PortalPrefixTest.php` |
| E5 | The snapshot produces byte-identical output on two runs | `tests/Feature/Console/SnapshotTest.php` |
| E6 | The snapshot refuses to wipe a directory it did not write | `tests/Feature/Console/SnapshotTest.php` |
| E7 | The snapshot leaves nothing pointing at localhost | `tests/Feature/Console/SnapshotTest.php` |

## Known flaws, pinned on purpose

These are wrong, known to be wrong, and held in place by tests so that fixing them
is a decision rather than an accident. Do not "fix" one without a decision entry.

| Flaw | Why it stays | Pinned by |
| --- | --- | --- |
| Merging conflicting metadata by richness rather than correctness | Ported behaviour from the .NET app, which took it from the original Next.js app | `tests/Feature/Services/BookSearchServiceTest.php` |
| Every non-Latin title collapsing into one de-duplicated entry | Same origin; the normalisation strips non-Latin characters entirely | `tests/Feature/Services/BookSearchServiceTest.php` |
| No database-level rating bound | Laravel and SQLite cannot express it; see the gap above | `tests/Feature/Database/SchemaConstraintsTest.php` |
