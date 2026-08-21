# Glossary

What the words in this codebase mean here. Several of them mean something slightly
different in the .NET original, in Laravel generally, or in ordinary English, and
those are the ones worth reading.

## Domain

**Book**
A catalogue entry: title, author, cover, page count, publication year, and the
provider ids it was found under. One row per work, shared by every reader.
A book exists independently of anyone having read it. Model: `App\Models\Book`.

**Read entry**
One reader finishing one book on one date, with a format and an optional rating.
This is the record the app is actually about. A reader can log the same book more
than once, on different dates, but not twice on the same date.
Model: `App\Models\ReadEntry`.

**Library**
One reader's collection of read entries. Never a shared or global thing. "Your
library" and "the library page" always mean the acting reader's entries only.

**Format**
How a book was consumed: Book, Audiobook or Ebook. Stored as the readable string,
not an ordinal, because the .NET source stores it that way.
Enum: `App\Enums\Format`.

**Rating**
0 to 5 stars, or absent. **0 is a real rating and is not the same as unrated.**
The column is nullable for that reason, and code that treats a falsy rating as
"no rating" is a bug. The 0 to 5 bound is enforced in request validation, not in
the database.

**Finished on**
The date a reader finished a book. A date with no time component, which PHP has no
native type for; `App\Casts\DateOnly` is the stand-in. It is part of the uniqueness
key for a read entry.

**Reader**
A user. The app says "reader" in its interface and "user" in its schema, and both
mean the same row in `users`.

**Acting reader**
Whose library the current request is operating on. There is no authentication, so
this is held in the session and switched from the navigation bar.
Resolved by `App\Services\CurrentUser`.

**Demo reader / demo user**
The same thing as the acting reader, named to make clear it is a stand-in for
authentication rather than an account system. The switcher is a demo affordance and
disappears when real authentication lands.

**Public feed**
The 20 most recent read entries across all readers, on the home page, cached for
60 seconds. The only place one reader sees another reader's activity.

**Have I read this?**
The lookup on the log page: given a search query, does the acting reader already
have a matching entry. `ReadLogService::checkIfRead()`.

## The book providers

**Provider**
An external book catalogue the app searches. There are two: Open Library and
Google Books. Each has one client class under `app/Services/External/`.

**Merge**
Combining both providers' results into one list: concatenate Open Library first,
then de-duplicate by normalised title plus author, keeping whichever duplicate
carries more metadata. Ties keep the first seen, which is why the ordering matters.

**Failure tolerance**
A provider being unreachable must never sink the search. One provider failing
leaves the other's results intact, and both failing produces an empty list rather
than an error page. This is inherited behaviour and there are tests for it.

## The AI search

**Ask your library**
The natural-language search on the library page: a question in plain words,
answered from the acting reader's own entries by a local model. The one feature
with no counterpart in the .NET original.

**Ollama**
The local model runner the AI search talks to, over HTTP on `localhost:11434` by
default. **Always optional.** Unreachable Ollama degrades the search to plain title
matching with a visible notice, and nothing else in the app changes.

**Layer 1, exact filters**
Format words, ratings and years pulled out of the question with plain patterns and
turned into `WHERE` clauses. Deterministic, no model involved.
`App\Services\Ai\LibraryQuestion`.

**Layer 2, embeddings**
Each entry rendered as one short text, embedded once by `nomic-embed-text` and
stored as JSON. The question is embedded the same way and cosine similarity in PHP
ranks the candidates. `App\Services\Ai\EntryEmbedder`.

**Layer 3, the chat model**
A small chat model phrases an answer from the top-ranked entries only, returning
JSON with the ids it relied on. `App\Services\Ai\LibraryAsk`.

**Citation validation**
Dropping any id the model returns that was not in the list it was shown. This is
what makes "it can only cite what it saw" enforced rather than hoped for.

**Degrading downward**
The rule the AI feature is built on: each layer may fail into the one below it.
Layer 3 failing still shows layer 2's ranked entries. Layer 2 failing falls back to
the plain title search. Nothing about the feature is allowed to become required.

**Relaxed filters**
When layer 1's filters match nothing, they are dropped rather than answering
"nothing", and the model is told they were dropped. The page shows no "Looked at:"
line in that case, so the reader can see the constraint went away.

**Warm / cold**
A cold model has to be loaded into memory before it answers, which measured at 47
seconds for the first question. Warm is 0.5 to 4 seconds.
`php artisan readlog:ask --warm` does the loading up front.

## Infrastructure

**Snapshot**
A static, browsable copy of the seeded app written as plain HTML by
`php artisan readlog:snapshot`. It is what the public URL serves when the author's
machine is off. Nothing on it is live.

**Portal**
The portfolio site at `mikkonumminen.dev` that serves this app under
`/readlog-laravel`. The path prefix is announced to the app with two headers and
handled by `App\Http\Middleware\PortalPrefix`.

**Tunnel**
A temporary public URL for the locally running app, opened for the length of a
demo and closed afterwards. See DEMO.md.

**Smoke check**
`php artisan readlog:smoke`: a short pass or fail table over a running instance.
Health route, home page, database, migrations, demo data, providers.
WARN never fails the run; FAIL does.

**Docs check**
`php artisan readlog:docs-check`: verifies that what the documentation claims still
matches the repository. Part of `composer verify`.

## Words that mean something specific here

**Counterpart**
The .NET file or construct a given PHP file was ported from. Named in the docblock
of almost every class. "No counterpart" means the thing was added in this port and
is not part of the ported specification.

**Decision**
A numbered row in `DECISIONS.md`: one judgement call, one line of reasoning. Not a
design document. Appended to, never rewritten.

**Invariant**
Something that must stay true, paired with the test that proves it. Listed in
`docs/INVARIANTS.md`.

**Pinned**
Held in place by a test that asserts the current behaviour, including behaviour
known to be imperfect. A pinned flaw is deliberate: the test documents the gap so
that changing it is a decision rather than an accident.

**Deliberately not done**
Scope that was considered and skipped on purpose, with the reasoning recorded.
Listed in STATUS.md. Not a backlog item and not an oversight.
