---
name: mikko-laravel-audit
description: Audit for Laravel / Eloquent / PHP anti-patterns that generic audits miss — env() outside config (null after config:cache), stateful singletons, session reads in the domain layer, N+1 queries, mass assignment, raw-SQL interpolation, check-then-act races, SQLite-vs-Postgres dialect drift, unescaped Blade output, unscoped ownership queries, fixed-path shared state, non-scalar cache payloads. Five parallel subagents over non-overlapping scopes; each check pairs a smell with a legitimate counter-example, most drawn from this very codebase. Runs a pre-flight fit check first — aborts cleanly if the codebase isn't Laravel. Read-only by default; writes severity-ranked results to docs/audits/laravel-<date>.md in a format that passes this repo's readlog:docs-check. With --prs it goes one phase further — lands the fixes on area-named branches as PRs, runs a high-effort code review on each PR, and fixes every confirmed review finding; merging stays human. Use for "audit my Laravel code", "review this Eloquent usage", "check the Blade views", "find framework-shaped bugs", or before merging a substantial PHP PR.
barney: Checks your Laravel code for the gotchas generic audits skip — env() that goes null in production, singletons that leak one user's session to another, N+1 queries, mass assignment, raw SQL with variables glued in, queries that behave differently on SQLite and Postgres. Five reviewers run in parallel; every finding has a concrete counter-example. Bails fast if it's not a Laravel codebase. Add --prs and it also opens fix PRs, reviews them, and fixes what the review finds — you still do the merging.
---

# laravel-audit

Reads a Laravel codebase (or a specified directory) looking for anti-patterns specific to **Laravel, Eloquent, Blade, and idiomatic PHP 8**. Reports findings in a markdown report under `docs/audits/`. **Read-only by default**; the human decides which findings are real. With `--prs` it carries confirmed findings through to reviewed fix PRs (Phase 4) — and still never merges.

Project-local skill: the Laravel analogue of the user-wide `mikko-dotnet-audit`, created because the `mikko-*` detection matrix has no PHP fingerprint (`composer.json` is never read — `/mikko-help --detect` reports `language: unknown` on this repo) and no installed stack audit covers Laravel.

## Why this skill exists separately from `audit` / `ai-codegen-smell-audit`

The general audits catch language-agnostic concerns. Neither systematically flags **Laravel-shaped** hazards: `env('OLLAMA_URL')` in a service reads fine to a generic auditor, but after `php artisan config:cache` (which this repo's Docker entrypoint runs on every container start — decision 57) it silently returns `null` in production while working perfectly in dev. A `$this->app->singleton(CurrentUser::class)` registration looks like an optimisation, but the class holds a `Session`, so it hands one reader's library to the next request — this exact bug was tried twice in this repo and caught by the suite (see `app/Services/CurrentUser.php`). `{!! $description !!}` is invisible to a robustness pass but is stored XSS if the description is third-party HTML.

This skill is the **Laravel-specific reviewer** — the third lens, not a replacement: run `mikko-audit` for robustness and `mikko-ai-codegen-smell-audit` for codegen texture; run **this** for the framework-specific shapes those two don't frame. (`mikko-llm-injection-audit` remains the right tool for the Ollama prompt boundary — that scope is deliberately NOT duplicated here.)

## Pre-flight check — IS THIS EVEN A LARAVEL CODEBASE?

**Before doing anything else, verify the target looks like Laravel.** If it doesn't, abort cleanly with a one-line message and a pointer to a more appropriate skill.

Procedure: **run the bundled script** — it performs this pre-flight AND Phase 1.5's candidate gathering in one deterministic pass (one Bash call, ~zero judgement tokens):

```
python .claude/skills/mikko-laravel-audit/laravel-audit.py [--source PATH] [--json]
```

Exit 0 = proceed; exit 2 = pre-flight bail; exit 3 = a seed grep ERRORED (healthy checks still print — investigate before trusting the run); exit 64 = bad invocation (unknown flag, missing `--source` path). **Key on the output text, not only the code**: a bail always prints a line starting `pre-flight: aborting`, while a bare interpreter failure (wrong script path) also exits 2 but prints no such line. `--force` overrides a fit-matrix bail, never a structural one: git missing or not-a-work-tree stay fatal, because the candidate pass IS git grep. On anything but a small repo pass `--out FILE` — the full map goes to the file and stdout carries only the summary, so a tool-output cap cannot silently truncate the trailing groups into "cleanly skipped". If python is unavailable, fall back to the manual steps below; they and the script's decision matrix are the same:

1. **`Glob` for `composer.json` + `artisan`** at the root. Read `composer.json`'s `require` for `laravel/framework` and the PHP constraint.
2. **`Glob` for hand-written PHP** under `app/` (this is the Laravel convention; `vendor/` is never counted) and **Blade templates** under `resources/views/`.
3. **Decision matrix:**

| `laravel/framework` in composer.json? | hand-written `app/**.php`? | Verdict |
| --- | --- | --- |
| (git missing, or not a git work tree) | any | **Bail, not forceable** — the candidate pass reads tracked files via git grep, so without git there is nothing to gather. |
| Yes | files exist but none are git-tracked | **Bail, forceable** — git grep reads the index, so a fresh scaffold would audit as empty. `git add` first. |
| Yes | Many (≥10) | **Proceed.** If no Blade templates, skip the web-surface checks that need them and say so. |
| Yes | Few (1–9) | **Proceed with note** — "small surface, patterns may not show at scale". |
| Yes | None | **Bail** — "framework installed but no app code found; pass `--source <path>`." |
| No, but PHP files exist | Many | **Bail** — "PHP but not Laravel. The Eloquent/Blade/container checks would be noise. Try `/mikko-audit`." |
| No | None | **Bail** — "not a PHP codebase. Try `/mikko-audit` or `/mikko-ai-codegen-smell-audit`." |

Output one of:

- `pre-flight: Laravel codebase confirmed (laravel/framework <version>, N tracked app/ files, M Blade templates, K untracked PHP). Proceeding.`
- `pre-flight: aborting — <reason>. Suggested alternative: <skill-name>.`

If the pre-flight bails, no audit runs and no report is written.

## When to invoke

- "audit my Laravel / PHP code", "review this Eloquent usage", "check my Blade views", "find framework bugs", "audit this PHP PR"
- Before merging a substantial backend PR (new services, new queries, new pages)
- On a codebase ported or scaffolded by an LLM — the container/Eloquent/Blade idioms are exactly where transliteration from another stack shows
- After `mikko-audit` (robustness) and `mikko-ai-codegen-smell-audit` (codegen texture) — this is the framework-specific layer

## When NOT to invoke

- **Not** on a non-Laravel codebase — the pre-flight catches it.
- **Not** as a substitute for `mikko-audit` (generic robustness) or `mikko-llm-injection-audit` (the LLM boundary — out of scope here by design).
- **Not** as a style linter. Formatting is Pint's job; type-level issues are PHPStan's. This skill notes what those tools report and moves on — it never re-lists their findings.
- **Not** an "is this idiomatic" vibe check. It checks **shapes** — concrete patterns with verifiable consequences.

## What this skill does NOT do

- **Does not modify code by default.** Output is a markdown report; the human picks fixes. With `--prs` it writes fixes — but only on fresh branches, only for findings the report already carries, and it **never merges**: every PR ends awaiting the human.
- **Does not flag a pattern that's documented or immunised.** An `// audit:laravel:ignore <check> — <reason>` comment on the line immunises it. So does a documented decision (see Calibration).
- **Does not re-report what the gate already enforces.** Pint, PHPStan level 6, the Pest suite, and `readlog:docs-check` run in `composer verify`; their findings are the gate's job.
- **Does not grade architecture.** "This should use a repository pattern / actions / DDD" is out of scope.

## Token economy — deterministic before dispatch

Everything that `git grep` and `Glob` can decide happens before a single subagent token is spent — and it ships as a **bundled script** (`laravel-audit.py`, stdlib only), because a deterministic procedure the model re-composes by hand each run is neither deterministic nor cheap (the mikko-skills-quality thesis, and this skill's own first run proved it by mis-quoting its own greps):

1. **Pre-flight + Phase 1.5 in one call** — `python .claude/skills/mikko-laravel-audit/laravel-audit.py` runs the fit check and every seed grep, printing the candidate map; the model reads one summary instead of composing ~20 grep invocations.
2. **Phase 1** — the repo's own toolchain (`pint --test`, `phpstan`, `composer audit`) runs off-context; the model reads only the summary.
3. **The candidate map gates Phase 2**: a check with zero candidates costs zero tokens, and a group with zero candidates is **not dispatched at all**.
4. **Phase 2** — subagents *only judge the candidate list they're handed*: ±10 lines of context per candidate, calibration rules applied, never scanning for discovery.

Cost scales with the number of *suspected* sites, not with codebase size.

## The checklist — five scopes, paired examples

Each check has a `Pattern`, a `Why`, a `Smell`, a `Legitimate` counter-example (the same shape when it's *fine* — many drawn from this repo), and a `Severity default`. **If a finding can't meet the smell+counter-example bar, it doesn't appear in the report.** The five groups (A–E) are the five parallel-subagent bundles.

````markdown
### Group A — container, configuration & composition

#### A1. env-outside-config
- **Pattern.** `env('X')` called anywhere except `config/*.php` — services, controllers, commands, migrations, Blade.
- **Why.** After `php artisan config:cache`, `env()` returns `null` outside config files. This repo's Docker entrypoint runs `artisan optimize` on every container start (decision 57), so the bug is invisible under `artisan serve` and total in the container.
- **Smell.** `$url = env('OLLAMA_URL', 'http://localhost:11434');` inside a service.
- **Legitimate.** `config('services.ollama.url')` reading a value that `config/services.php` mapped from env once. `env()` inside `config/*.php` is the designed place for it.
- **Severity default.** high (critical if the value gates a write or an auth decision).

#### A2. stateful-singleton
- **Pattern.** `singleton()` / `scoped()` / `instance()` binding of a class that holds a `Session`, `Request`, or per-user state.
- **Why.** The binding outlives one request; the next request gets the previous request's state. This repo hit it with `CurrentUser` (holds a `Session`) — tried twice, caught by the suite as one reader's library rendering for another. The class docblock and `AppServiceProvider::register()` both record it.
- **Smell.** `$this->app->singleton(CurrentUser::class);`
- **Legitimate.** Stateless services as singletons; no binding at all (auto-wiring resolves fresh per injection, which is this repo's default and the reason `register()` is empty).
- **Severity default.** high.

#### A3. http-context-in-domain
- **Pattern.** `session()`, `request()`, `auth()`, or their facades reached from `app/Services/` (or models / support DTOs) instead of taking explicit arguments.
- **Why.** This repo's load-bearing rule: services take `int $userId`; controllers resolve the reader and pass it down. That is what makes every ownership rule testable without a browser (ARCHITECTURE.md, "The one rule").
- **Smell.** `$userId = session('demo_user_id');` inside `ReadLogService`.
- **Legitimate.** `CurrentUser` itself — the one documented holder of the `Session`, injected via constructor. Controllers and middleware, where HTTP context belongs.
- **Severity default.** medium (high if it bypasses an ownership check).

#### A4. runtime-config-mutation
- **Pattern.** `Config::set(...)` / `config([...])` in production code without saving and restoring the previous value.
- **Why.** Mutates shared application state for everything that runs later in the same process — the test suite included.
- **Smell.** `Config::set('database.default', 'snapshot');` in a command, never restored.
- **Legitimate.** `readlog:snapshot` — saves the previous values first and restores them in a `finally`, with a comment naming why (the console container reuses the process). Tests via `config()->set()` inside `RefreshDatabase` isolation.
- **Severity default.** medium.

### Group B — Eloquent & database

#### B1. n-plus-one
- **Pattern.** Accessing a relation per-row (in a PHP loop or a Blade `@foreach`) on a collection loaded without `with()` / `loadMissing()`.
- **Why.** One query becomes N+1. Invisible at seed scale (14 entries), a page-killer at real scale.
- **Smell.** `ReadEntry::where('user_id', $id)->get()` then `@foreach ($entries as $e) {{ $e->book->title }}`.
- **Legitimate.** `ReadEntry::with(['book', 'embedding'])->where('user_id', $userId)` — the shape `LibraryAsk::candidates()` uses. A single-model page where one lazy load is one query.
- **Severity default.** high on a list page, medium elsewhere.

#### B2. unbounded-query
- **Pattern.** `Model::all()` or an unfiltered `->get()` on a table that grows with usage, with no `take()` / `limit()` / pagination.
- **Why.** Works with the seed data, degrades linearly with real use.
- **Legitimate.** **This repo deliberately has no pagination** — the library renders every entry, matching the .NET source, and STATUS.md lists it under known rough edges. That documented choice immunises the library page. The public feed is capped at 20 (`PUBLIC_FEED_SIZE`). A bounded lookup table is fine.
- **Severity default.** medium (informational where the no-pagination decision applies).

#### B3. mass-assignment
- **Pattern.** `create($request->all())`, `fill($request->all())`, `update($request->all())`, `$guarded = []`, or `forceFill()` fed from request input.
- **Why.** A crafted POST sets columns the form never showed — `user_id`, `id`, anything fillable. The Laravel twin of .NET overposting.
- **Smell.** `ReadEntry::create($request->all());`
- **Legitimate.** `$request->validated()` through a FormRequest, or this repo's pattern — `toData()` mapping validated input onto a `final readonly` DTO whose fields are the whole contract. Factories and seeders may `create([...])` freely.
- **Severity default.** high.

#### B4. raw-sql-interpolation
- **Pattern.** `DB::raw` / `whereRaw` / `selectRaw` / `orderByRaw` / `statement` with a PHP variable interpolated or concatenated into the SQL string instead of passed as a binding.
- **Why.** SQL injection when input-reachable; quoting bugs even when not.
- **Smell.** `->whereRaw("title LIKE '%$q%'")`
- **Legitimate.** Raw fragments with `?` bindings — this repo's escaped-LIKE lookup binds the pattern AND the escape character (decision 46 records that an earlier spliced backslash was checked on both engines and removed). Constant raw fragments with no variables.
- **Severity default.** critical if request-reachable, medium otherwise.

#### B5. check-then-act-race
- **Pattern.** `exists()` / `first()` followed by `create()` / `update()` where a unique constraint exists, without catching the violation or using a transaction that makes the pair atomic.
- **Why.** Two concurrent requests both pass the check; the loser gets an unhandled `UniqueConstraintViolationException` → 500.
- **Smell.** `if (! Book::where('open_library_id', $id)->exists()) { Book::create([...]); }`
- **Legitimate.** This repo's pattern: attempt the insert inside a savepoint, catch `UniqueConstraintViolationException`, confirm by re-query, then either surface the domain error or reuse the winner's row (`ReadLogService::logBook`, decisions 47, 91, 107). `firstOrCreate` narrows but does not close the window — fine when the violation is also caught.
- **Severity default.** high.

#### B6. dialect-drift
- **Pattern.** SQL that behaves differently on SQLite and PostgreSQL: `strftime`, `ILIKE`, `json_extract`, `::` casts, engine-specific date maths, or reliance on SQLite's lax typing / case-insensitive LIKE.
- **Why.** This app's production claim is "any standard PostgreSQL" and the whole suite runs on both engines (invariant P1). Dialect-specific SQL passes on the engine the developer runs and fails on the other — sometimes only in CI, sometimes only in production.
- **Smell.** `->whereRaw("strftime('%Y', finished_at) = ?", [$year])`
- **Legitimate.** `whereYear('finished_at', $year)` — the query builder's portable form, which `LibraryQuestion::apply()` chose for exactly this reason. `lower(title) LIKE lower(?)`, both sides lowered by the database, portable by construction.
- **Severity default.** high.

### Group C — outbound HTTP boundary

#### C1. missing-timeout
- **Pattern.** An `Http::` call on a request path with no explicit `timeout()` (directly or via config).
- **Why.** A hung provider holds a php-fpm worker for the default 30 s; this app runs a handful of workers behind a public URL, so a few stalled calls are a denial of service (the reasoning behind decision 106).
- **Smell.** `Http::get($url)->json();` on a page path.
- **Legitimate.** Timeouts threaded from config — `book_search.timeout` (10 s, matching the .NET HttpClient), the measured Ollama bounds (decision 103). Test code against fakes.
- **Severity default.** medium (high on a page path).

#### C2. unchecked-response
- **Pattern.** Consuming `->json()` / `->body()` without checking `successful()` / `ok()` or calling `throw()` first.
- **Why.** An error page parses as `null`; nulls propagate and surface far from the cause.
- **Smell.** `$data = Http::get($url)->json(); return $data['items'];`
- **Legitimate.** `->throw()` then catch at the boundary; or this repo's `settle()` pattern — each provider response unwrapped in its own try/catch after the pool resolves, so one provider failing cannot sink the other (invariants F1 to F4).
- **Severity default.** medium.

#### C3. secret-in-log
- **Pattern.** `Log::` calls that record exception messages or URLs which can carry credentials — an API key in a query string survives into the log line.
- **Why.** Guzzle puts the full request URL into connection-failure messages, and Google Books only accepts its key in the query string. Invariant S1: the key never appears in a log line.
- **Smell.** `Log::warning("search failed: {$e->getMessage()}");` where the message embeds the request URL.
- **Legitimate.** Messages routed through `Redact::apiKey()` first — one place for one rule (decision 38), used by both the search service and the smoke check.
- **Severity default.** high.

#### C4. stray-real-request
- **Pattern.** App or test code that can reach the network outside the `Http` facade — `file_get_contents('http…')`, `curl_init`, a hand-constructed Guzzle client — or tests that sidestep the fake.
- **Why.** The suite's promise is "no unfaked outbound request" (`Http::preventStrayRequests()`, invariant S4). Anything not going through the facade escapes both the fake and the guard.
- **Smell.** `file_get_contents("https://covers.openlibrary.org/{$id}.jpg")` in a command.
- **Legitimate.** `LiveProviderTest` — real calls, gated behind `BOOK_SEARCH_LIVE_TESTS=true`, asserting shape not data. Everything else through `Http::`.
- **Severity default.** high.

### Group D — web surface (Blade, routes, validation)

#### D1. unescaped-blade-output
- **Pattern.** `{!! ... !!}` on a value that isn't a compile-time constant and didn't pass through a sanitizer.
- **Why.** Blade escapes by default; `{!! !!}` opts out. On third-party HTML (book descriptions) that's stored XSS.
- **Smell.** `{!! $book->description !!}`
- **Legitimate.** `{!! $safeDescriptionHtml !!}` where the controller ran the value through `BookDescriptionSanitizer` (symfony/html-sanitizer, `defaultAction(Block)`) so the template has nothing left to decide — this repo's shape (invariant S5). Constant markup the app ships.
- **Severity default.** critical when the value crosses a trust boundary; medium otherwise.

#### D2. mutation-on-get
- **Pattern.** A `Route::get` handler that changes state — writes the database, mutates the session's acting identity, deletes.
- **Why.** GETs are prefetchable, cacheable, and CSRF-unprotected.
- **Smell.** `Route::get('/switch-user/{id}', ...)` writing the session.
- **Legitimate.** GET handlers that only read; the state change on POST with the CSRF token — this repo's `POST /demo-user` is the model. Writing to the session for flash/notice purposes on a redirect target is fine.
- **Severity default.** high.

#### D3. unscoped-ownership
- **Pattern.** Fetching a per-user record by id without constraining to the acting user — `Model::find($id)` / `findOrFail($id)` on an owned table, or implicit route-model binding without a scoped resolver.
- **Why.** Cross-reader access. This repo's ownership invariants (O1 to O8) also require 404, never 403, so existence doesn't leak.
- **Smell.** `ReadEntry::findOrFail($entryId)` in a controller action.
- **Legitimate.** The service pattern: `->where('user_id', $userId)->where('id', $entryId)->first()`, null → 404. Catalogue-level tables (`books`) are shared by design and have no owner to scope by.
- **Severity default.** critical.

#### D4. open-redirect
- **Pattern.** `redirect($request->input(...))` / `redirect()->away($userValue)` where the target is request-supplied.
- **Why.** `?return=https://evil.example` walks the user off-site from a trusted origin.
- **Smell.** `return redirect($request->query('return'));`
- **Legitimate.** `redirect()->route('feed')` and friends — named-route targets, which is all this repo uses (and must, given the portal prefix rewriting).
- **Severity default.** high.

#### D5. unvalidated-input
- **Pattern.** Request input flowing into queries or domain calls with no FormRequest, no `validate()`, and no explicit shape guard.
- **Why.** Arrays arrive where strings are expected (`?q[]=x` is a classic Laravel footgun), lengths are unbounded, types are assumed.
- **Smell.** `$q = $request->query('q'); Book::where('title', 'like', "%$q%")` with `$q` possibly an array.
- **Legitimate.** FormRequests for writes (this repo's `LogBookRequest` / `UpdateReadEntryRequest`); for reads, the explicit `is_string` + trim + cap shape — `stringOrNull()` / `intOrNull()` (decision 29) and the library page's `mb_substr(..., 0, 400)` on the ask parameter.
- **Severity default.** medium.

#### D6. csrf-exemption
- **Pattern.** Routes excluded from CSRF verification (`$except`, `validateCsrfTokens(except: ...)`).
- **Why.** Every exemption is a standing invitation for cross-site POSTs; each needs a reason.
- **Legitimate.** A webhook endpoint verifying its own signature, documented. This repo should have zero.
- **Severity default.** high.

### Group E — errors, state & lifecycle

#### E1. swallowed-exception
- **Pattern.** `catch` that neither logs, rethrows, recovers meaningfully, nor carries a comment naming why — including `catch (...) { return null/[]/false; }`.
- **Why.** The failure vanishes; debugging becomes archaeology.
- **Smell.** `try { $this->embed($entry); } catch (\Throwable) { }`
- **Legitimate.** This repo's provider-degradation catches: log through `Redact`, return `[]`, comment naming the Promise.allSettled inheritance. The unique-violation catch that treats a lost race as success, with the comment saying the winner's row is as good (decision 107). Has a log call or a recovery AND a reason.
- **Severity default.** high (critical when the swallowed block wrapped a write).

#### E2. broad-catch-eats-signals
- **Pattern.** `catch (\Exception)` / `catch (\Throwable)` around code that raises framework control-flow exceptions — `abort()`'s `HttpException`, `ValidationException`, model-not-found — converting an intended 404/422 into a generic success-shaped result.
- **Why.** The framework signals through exceptions; a broad catch turns "not found" into "empty result", masking both errors and aborts.
- **Smell.** `try { $entry = $this->service->getEntry(...) ?? abort(404); ... } catch (\Exception) { return back(); }`
- **Legitimate.** Broad catch at a true boundary (a console command's outermost handler; the settle pattern around a provider call that cannot raise framework signals), or a catch that re-throws `HttpException` subclasses first.
- **Severity default.** medium.

#### E3. fixed-path-shared-state
- **Pattern.** Commands or tests writing a fixed filename under `storage/` or the system temp dir.
- **Why.** The checkout becomes shared mutable state: two suite runs on one working copy destroy each other. This repo's own history — the snapshot's fixed `snapshot.sqlite` made the suite red on a third of runs (decision 121); the concurrent-suite property is now load-bearing and tested (decisions 125, 130, 131, 138).
- **Smell.** `File::put(storage_path('app/export.json'), $data);` reachable from two processes.
- **Legitimate.** Per-process names — `snapshot-<pid>-<random>.sqlite` — with a start-up sweep for stale leftovers; pid-scoped test directories.
- **Severity default.** high.

#### E4. non-scalar-cache
- **Pattern.** `Cache::put` / `remember` of Eloquent models, DTOs, or closures — anything that isn't scalars/arrays of scalars.
- **Why.** The database cache store with `serializable_classes => false` refuses or mangles object payloads; the array store in tests happily accepts them, so the bug ships. This repo's public feed caches arrays of scalars and rehydrates for exactly this reason (decision 21, invariant P5, pinned by `PublicFeedCacheTest`).
- **Smell.** `Cache::remember('feed', 60, fn () => ReadEntry::with('book')->take(20)->get());`
- **Legitimate.** Scalars in, DTOs rehydrated on the way out — the `PublicRead` round-trip. Short-lived probe flags (`Cache` of a bool with a TTL, as the Ollama availability probe does).
- **Severity default.** high.

#### E5. wall-clock-fragility
- **Pattern.** `now()` / `today()` / `Carbon::now()` in seeders, migrations, or uniqueness-relevant comparisons — anywhere determinism or idempotency matters.
- **Why.** Seeding today and re-seeding tomorrow produces different rows, breaking idempotency (invariant P3). Comparisons against a moving now flake at midnight and around DST.
- **Smell.** `'finished_at' => now()->subDays(3)` in a seeder.
- **Legitimate.** Dates counted back from a fixed anchor day (decision 49 — this repo's seeder). Request-time `now()` for display or for a validated "not in the future" bound. `CarbonImmutable::now()` injected as a parameter for testability (`LibraryQuestion::parse(..., $today)`).
- **Severity default.** medium.
````

## Calibration rules

Blocking — apply before recording any finding.

- **DECISIONS.md is the spec.** This repo keeps a numbered, topic-indexed decision log; deliberate oddities live there (no pagination, the merge flaws kept on purpose, the missing rating constraint, the no-auth demo reader). **Check the topic index before flagging** — a decision-backed behaviour is immune, and flagging it wastes the reader's trust. `docs/INVARIANTS.md` has a "pinned on purpose" table with the same force.
- **Documented intent immunises a line.** A comment naming the choice means the author considered it. `// audit:laravel:ignore <check> — <reason>` is the explicit opt-out.
- **Trust-boundary handling is immune.** Defensive shaping of request input, provider responses, and DB exceptions at the boundary is the pattern, not the smell.
- **The gate's tools own their findings.** Pint, PHPStan level 6, Pest, `readlog:docs-check` run in `composer verify`. Note their status; never re-list their output as findings.
- **Excluded paths.** `vendor/`, `storage/`, `bootstrap/cache/`, `public/build/`, `database/database.sqlite`, generated `docs/machine/*.json`. Framework-stock config files are context, not audit targets.
- **Tests get a partial pass.** Tests fake, force, and reach into internals by design. Flag tests only for E1 (swallowed exceptions), E3 (fixed shared paths), and C4 (escaping the HTTP fake).
- **One occurrence is data; many is a fingerprint.** Density (same check ≥5 times in a file) upgrades severity one level and earns a note.

## Report constraints — this repo audits its own documentation

`readlog:docs-check` scans `docs/` recursively and **fails `composer verify`** on: any em dash, any relative link that doesn't resolve, any `#fragment` link whose heading doesn't exist (a `#L48` fragment on a PHP target fails this), and stale generated files. Therefore the report:

- contains **zero em dashes** (the repo's prose rule, enforced);
- cites findings as plain code spans — `` `app/Services/Foo.php:48` `` — **never** as `[...](file#L48)` links;
- avoids the checked count phrasings ("N numbered entries", "N invariants") unless the number is exact;
- after writing, **run `php artisan readlog:docs-check`** and fix the report until it passes. A report that breaks the gate is itself a finding against this skill.

## Procedure

### Phase 0 — pre-flight

See above. Honour `--source <path>`. Bail politely if not Laravel (unless `--force`).

### Phase 1 — static analysis (best effort, read-only)

Run from the repo root; capture output off-context; skip cleanly with a one-line reason if unavailable. **Never run anything that mutates** (no `pint` without `--test`, no `migrate`, no `db:seed`).

1. `vendor/bin/pint --test` — formatting drift (report count only).
2. `vendor/bin/phpstan analyse` — level and error count (their findings are theirs).
3. `composer audit` — known-vulnerable dependencies (needs network; skip cleanly offline).
4. `composer outdated --direct` — optional context, never findings.
5. `vendor/bin/pest` is **off by default** (the gate runs it); only on request.

### Phase 1.5 — candidate gathering (deterministic, no AI tokens)

**Normally already done**: the bundled `laravel-audit.py` executed every seed during Phase 0 and printed the map: a `counts:` line covering every check, the zero-candidate list, the grouped `file:line` sites, and a named line for every way a zero can lie — `ERROR` (the grep failed), `SKIPPED` (the check's scope directories do not exist here), `PARTIAL` (some of them do not). Only an ordinary zero means searched-and-clean. Merged seed ids (B12, C12, E12, B1_blade) cover two checks or feed one; judges tag findings with the specific check id. The script invokes git grep as an argument vector with `-e` and `--`, so dash-leading and quote-bearing patterns are structurally safe. **The script's seed table is the executable source of truth; the table below documents it and is the manual fallback** — change them together (the freshness check pins both).

Manual fallback, only when python is unavailable:

`git grep -nE -e "<pattern>" -- <paths>` over tracked files (which already excludes `vendor/` and `storage/` via .gitignore), one pass per seed pattern, scoped to the paths shown. **The `-e` is mandatory, not style**: five seeds begin with `->`, and without `-e` git grep parses them as options and yields a silent zero — the first run shipped exactly this bug and lost six checks to it until an impossible zero (on `$request->query(`, which exists in any Laravel controller) exposed it. Double-quote patterns for the shell; the C4 seed uses a `.` wildcard precisely so no seed has to embed a quote. Collect `{check, file:line, matched text}`, build the map grouped A–E, record per-check counts, and **gate dispatch**: a group with zero candidates is not dispatched.

The fenced block below is the copy source. It is deliberately NOT a markdown
table: a table cell needs its pipes escaped as `\|`, and POSIX ERE reads an
escaped pipe as a literal character, so a pattern pasted from a table silently
matches nothing (measured: 17 of 24 rows). Inside the fence every pipe is real.
Fields are separated by ` :: `; merged ids share one seed between two checks
(B12 = B1+B2, C12 = C1+C2, E12 = E1+E2; B1_blade feeds B1).

```text
check :: ERE pattern :: pathspecs
A1 :: \benv\( :: app routes resources database
A2 :: ->singleton\(|->scoped\(|->instance\( :: app
A3 :: \bsession\(|\brequest\(|\bauth\(|Session::|Auth:: :: app/Services app/Support app/Models
A4 :: Config::set|config\(\[ :: app
B12 :: ->get\(\)|::all\(\) :: app
B1_blade :: ->[a-zA-Z_]+-> :: resources/views
B3 :: ->all\(\)|guarded|forceFill :: app
B4 :: DB::raw|whereRaw|selectRaw|orderByRaw|havingRaw|DB::statement\(|->statement\( :: app database
B5 :: ->exists\(\)|firstOrCreate|updateOrCreate :: app
B6 :: strftime|ILIKE|json_extract|::text|::date|RANDOM\(\) :: app database
C12 :: Http:: :: app
C3 :: Log:: :: app
C4 :: file_get_contents\(.http|curl_init|new Client\( :: app tests
D1 :: \{!! :: resources/views
D2 :: Route::get :: routes
D3 :: ::find\(|findOrFail\( :: app
D4 :: redirect\(|->away\( :: app
D5 :: ->query\(|->input\( :: app/Http
D6 :: \$except|validateCsrfTokens :: app bootstrap
E12 :: catch[[:space:]]*\( :: app tests
E3 :: storage_path\(|sys_get_temp_dir\(|tempnam\( :: app tests
E4 :: Cache::|\bcache\( :: app
E5 :: \bnow\(\)|\btoday\(\)|Carbon::now|CarbonImmutable::now :: app database
```

What the judge narrows each check on (patternless, so the table form is safe):

| Check | Judge narrows on |
| --- | --- |
| A1 | any hit outside config/ is a candidate |
| A2 | bound class holds Session/Request/user state |
| A3 | not CurrentUser; not constructor-injected contracts |
| A4 | no save+restore in finally |
| B12 | B1: relation walked per-row downstream without with(); B2: growable table, no bound, no decision cover |
| B1_blade | a relation chain echoed inside a loop |
| B3 | request-fed create/fill/update, not validated()/DTO |
| B4 | variable interpolated vs bound with ? placeholders |
| B5 | unique index + no violation handling |
| B6 | engine-specific behaviour between SQLite and Postgres |
| C12 | C1: timeout absent; C2: body read unguarded |
| C3 | message can embed a credentialled URL |
| C4 | bypasses the Http facade |
| D1 | value not sanitised upstream |
| D2 | handler writes state |
| D3 | owned table, no user_id scope |
| D4 | request-supplied target |
| D5 | no shape guard before use |
| D6 | any exemption |
| E12 | E1: empty or silent catch; E2: eats framework signals |
| E3 | fixed name, shared between processes |
| E4 | non-scalar payload |
| E5 | determinism-relevant site |

### Phase 2 — parallel subagents (up to five, candidate-gated)

Dispatch only the groups with candidates — one message, parallel `Agent` calls, `subagent_type: "Explore"` (read-only). Each subagent gets **its group's candidate list** and judges those sites: read ±10 lines around each, apply the calibration rules (including the DECISIONS.md topic index for anything that smells deliberate), emit a finding only when the shape matches the smell and not the legitimate counter-example.

Append to every subagent prompt:

> You are handed a candidate list — file:line sites a deterministic grep pre-pass found for your group's checks. **Judge each candidate; do not scan the tree for new ones.** Read ±10 lines of context; follow a pointer one hop (a config key to its definition, a variable to its source) but don't prospect beyond it. This repository documents its deliberate oddities in DECISIONS.md (topic-indexed at the top) and docs/INVARIANTS.md ("pinned on purpose"); a documented behaviour is immune. Output one line per finding, exact template (no em dash anywhere — the report must pass this repo's docs-check):
> `` - `path/File.php:NN` [severity] <check-id>: one-line description ``
> severity ∈ {critical, high, medium, low}. Emit nothing for a candidate matching the legitimate counter-example. Candidates arrive under seed ids; a merged id (B12, C12, E12, B1_blade) spans two checks or feeds one — tag every finding with the specific check id, never the seed id. Every finding cites a real file:line. Cap your reply at ~400 words.

### Phase 3 — aggregated report

Write `docs/audits/laravel-<YYYY-MM-DD>.md` (create the directory if absent; suffix `-v2` on a same-date collision — never overwrite). Recount the severity tally so the summary matches the body exactly. Tally rows may merge checks sharing a seed (B1/B2, C1/C2, E1/E2), mirroring the script's merged ids; findings themselves always carry the specific check id. The report lands in the audited tree's own `docs/audits/`. **Then run the documentation gate**: in this repository that is `php artisan readlog:docs-check`, fixed until it passes. On another Laravel tree (`--source` elsewhere) that command does not exist; run that repo's own docs gate if it has one, otherwise skip the step and record the skip on the report's Coverage line.

### Phase 4 — reviewed fix PRs (opt-in, `--prs`)

By default the skill stops at the report. With `--prs`, it carries the findings the rest of the way — because in this repository the audit loop has never actually ended at a defect list: every review pass so far (PRs 9, 12, 20, 24) ended in a reviewed PR whose review found more than the pass itself did. This phase names that ending so it happens by procedure, not by memory.

Runs **only when Phase 3 recorded findings**; on a zero-finding run there is nothing to land and the phase reports exactly that (the audit's own artifacts — a new report, a changed skill — still land through this same loop, driven by the operator rather than the flag).

1. **Group findings into branches.** One area-named branch per group that has findings (`fix/laravel-eloquent`, `fix/laravel-web-surface`, …); a lone finding may share a batch branch. Never work on `main` — this repo's contributing rule.
2. **Fix, with the report as the spec.** Each fix addresses a reported `file:line` finding and nothing else — no drive-by refactors. Behaviour changes that touch a documented decision get a new DECISIONS.md row (the append-only rule).
3. **Gate every branch.** `composer verify` green before any PR opens. New behaviour gets a test in the existing style; a fix without a failing-first test for the reported shape should say why.
4. **Open the PR with a self-review body** — what changed, why, verification output pasted as it ran, what was deliberately not done. House convention: the PR body is part of the product.
5. **Review the PR at high effort** (`/code-review <PR#> high` here; any adversarial reviewer elsewhere). This step is load-bearing, not ceremonial — in this repo's history the review of the audit branch found fifteen issues the audit itself could not see, two of them in the audit's own fixes.
6. **Verify each review finding before acting on it** — reviewers confabulate; reproduce or refute against the file, exactly as Phase 2 judges candidates. Fix every confirmed finding on the same branch, re-run the gate, push, and update the PR body with a "review round" section recording what was found and what was wrong-first.
7. **Stop.** Report PR URLs and CI status. **Merging is the human's move, always** — the skill never merges, never force-pushes, and never deletes a branch it did not create.

If the review loop finds a defect in the *skill's own checks* (a check that should have caught what the review caught), record it in the skill's Failure modes and add the missing check or seed — the reviewer improving the auditor is the point of running both.

## Output schema

````markdown
# Laravel audit, {YYYY-MM-DD}

## Summary
- Commit audited: `<sha>` on branch `<branch>`
- Pre-flight: {one-liner}
- Coverage: Phase 1 {per tool: ran/skipped}; Phase 2 {N of 5} groups dispatched
- Total findings: N (critical: N, high: N, medium: N, low: N)

## Per-check tally
| Check | Candidates | Findings |
| --- | ---: | ---: |

## Static analysis
{Phase 1 per-tool status}

## How each group cleared
{required on a zero-finding run, recommended always: per group, what the judge
verified and which counter-example each notable candidate matched. This section
is what makes a zero auditable; without it a clean run is just an assertion.}

## Findings by area
### A: container, configuration and composition
- `app/....php:NN` [severity] A1 env-outside-config: description
### B: Eloquent and database
### C: outbound HTTP boundary
### D: web surface
### E: errors, state and lifecycle

## Recommended next steps
{grouped by severity, critical first; suggest area-named fix branches}

## Process notes
{any deviation, tooling bug, or lesson from this run; omit only if truly none}

## What is verifiable vs editorial
{the standard table}
````

## Output discipline

- **Never fabricate.** Every finding cites a real file:line. In doubt, leave it out.
- **No inline fixes.** The report is a defect list; fixes land on reviewed branches.
- **Severity tally matches the body.** Recount before writing the summary.
- **Read-only by default.** The human judgement separating signal from noise is the product. Under `--prs`, writes are confined to fix branches and every one arrives pre-reviewed; `main` is never touched and nothing merges itself.
- **The report passes the repo's own gate.** See Report constraints.

## Flags

- `--source <path>` — audit only the given directory.
- `--force` — override a fit-matrix bail (printed as OVERRIDDEN, recorded in the report header). Structural bails are not overridable: without git, or outside a work tree, there is nothing to gather, and forcing the wrong tree buys zeros, not findings.
- `--out FILE` — write the full candidate map to FILE; stdout keeps only the summary. Use on anything but a small repo: tool-output caps truncate stdout silently, and a truncated map reads as cleanly-skipped groups.
- `--prs` — after the report, land the findings as reviewed fix PRs (Phase 4): area-named branches, gate-green commits, self-reviewed PR bodies, a high-effort machine review per PR, and every confirmed review finding fixed before handing over. No-op on a zero-finding run. Never merges.

## Failure modes

- **A zero from a tool is a claim to verify, not a fact.** The first run's candidate pass silently zeroed six checks: five seed patterns begin with `->` and git grep parsed them as command options. The tell was a zero on a pattern that could not be zero. The bundled script closes the bug class (argument-vector invocation, per-check ERROR lines instead of silent zeros), and the manual fallback mandates `-e`; a surprising zero-candidate check still gets re-verified against a known call site before its group is skipped.
- **Two copies of the seed table can drift.** The script's `SEEDS` dict is executable truth; SKILL.md's table is documentation and fallback. An edit to one without the other makes the fallback and the normal path audit different things. Change them together in the same commit. The freshness markers (`B1_blade`, `B1 (Blade)`, `DB::statement` in both files) catch gross drift only, not cell-level drift — the PR 27 review found four cells already differing under weaker markers — so the binding rule is the human one, and the fenced block is the fallback's only copy source.
- **`git grep` misses untracked new files.** Files added but never `git add`ed escape the candidate pass; `git status` in the pre-flight notes any untracked PHP so the reader knows.
- **The Blade N+1 seed sees echoed chains only.** `->[a-zA-Z_]+->` in `resources/views/` catches a relation chain echoed in a template; a chain assembled in a PHP variable before the loop, or a lazy load reached through a candidate-free call path, still escapes. Group B's judgment of the `->get()` sites covers most of the rest.
- **Heuristic matching.** Ownership scoping (D3) and singleton statefulness (A2) need the auditor to connect a call site to a class definition — expect occasional false positives; the counter-example column tells the reader when a hit is fine.
- **Octane/queue-worker lifetimes.** This skill assumes php-fpm's request-per-process model (what this repo runs). Long-lived worker hazards (static leaks, container bleed) are out of v1 scope.

## What's verifiable vs editorial

| Claim | Source of truth | Verifiable? |
| --- | --- | --- |
| Is this Laravel? | composer.json + artisan + app/ | ✅ pre-flight |
| Does pattern X appear at file:line? | The source file | ✅ |
| Is it a bug *here*? | Human judgement + counter-example | 🟡 heuristic |
| Is it deliberate? | DECISIONS.md / INVARIANTS.md | ✅ documented |
| Which fix to apply | Out of scope | — |

## Freshness check

```toml
[[check]]
kind = "file_contains"
path = "SKILL.md"
root = "skill_dir"
pattern = "env-outside-config"

[[check]]
kind = "file_contains"
path = "SKILL.md"
root = "skill_dir"
pattern = "pre-flight"

[[check]]
kind = "file_contains"
path = "SKILL.md"
root = "skill_dir"
pattern = "candidate gathering"

[[check]]
kind = "file_contains"
path = "SKILL.md"
root = "skill_dir"
pattern = "DECISIONS.md is the spec"

[[check]]
kind = "file_contains"
path = "SKILL.md"
root = "skill_dir"
pattern = "reviewed fix PRs"

[[check]]
kind = "path_exists"
path = "laravel-audit.py"
root = "skill_dir"

[[check]]
kind = "file_contains"
path = "laravel-audit.py"
root = "skill_dir"
pattern = "B1_blade"

[[check]]
kind = "file_contains"
path = "SKILL.md"
root = "skill_dir"
pattern = 'B1 \(Blade\)'

[[check]]
kind = "file_contains"
path = "laravel-audit.py"
root = "skill_dir"
pattern = "DB::statement"

[[check]]
kind = "file_contains"
path = "SKILL.md"
root = "skill_dir"
pattern = "DB::statement"
```
