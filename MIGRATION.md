# Migrating ReadLog from ASP.NET Core to Laravel

This is the record of porting [readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet),
an ASP.NET Core 8 Razor Pages application, to Laravel 13 on PHP 8.4. I have been
writing C# and .NET for years. This was my first PHP application and my first
Laravel application, so the notes below are as much about what a .NET developer
walks into as about the code itself.

The source was treated as a specification. Where readlog-dotnet does something
odd, the odd thing was ported rather than fixed, and the oddity is written down.
Two of them are pinned by tests that assert the current broken behaviour, so
nobody has to guess whether it was deliberate.

Everything here refers to code you can read. Paths starting `src/ReadLog.Web/`
are in readlog-dotnet. Everything else is in this repository.

## The stack, side by side

| Concern | readlog-dotnet | this repository |
| --- | --- | --- |
| Runtime | .NET 8 (LTS) | PHP 8.4 |
| Framework | ASP.NET Core, Razor Pages | Laravel 13 |
| Data access | EF Core (data mapper) | Eloquent (Active Record) |
| Database | SQLite | SQLite |
| Views | Razor + Bootstrap 5 | Blade + one hand-written stylesheet |
| Tests | xUnit | Pest 4 |
| Auth | ASP.NET Core Identity | none in version 1, see below |
| HTTP client | typed `HttpClient` | `Http` facade with `Http::pool` |
| HTML sanitising | Ganss.Xss | symfony/html-sanitizer |

## The mapping, building block by building block

### Project layout and tooling

| readlog-dotnet | here | note |
| --- | --- | --- |
| `ReadLog.sln`, `*.csproj` | `composer.json` | one dependency manifest, no project files |
| `dotnet run` | `php artisan serve` | |
| `dotnet build` | nothing | PHP has no compile step, which matters more than it sounds; see the pain points |
| `dotnet test` | `vendor/bin/pest` | |
| `dotnet ef migrations add` | hand-written `database/migrations/*.php` | generated versus written; see Active Record below |
| `dotnet ef database update` | `php artisan migrate` | |
| `dotnet format` | `vendor/bin/pint` | |
| `Directory.Build.props` (nullable, analysers) | nothing equivalent | PHP has no compiler switches to turn on |
| `.github/workflows/ci.yml` | `.github/workflows/ci.yml` | same job, different toolchain; this one also runs on Postgres and brings the compose stack up |
| `Dockerfile` (SDK image builds, aspnet image runs) | `Dockerfile` plus `compose.yaml` | .NET compiles into the image; PHP copies the source in. Kestrel is server and runtime; here nginx fronts php-fpm |
| `Database.Migrate()` at startup in `Program.cs` | `docker/entrypoint.sh` | .NET migrates inside the process; php-fpm runs no code until a request, so the entrypoint does it |

### Domain

| readlog-dotnet | here |
| --- | --- |
| `src/ReadLog.Web/Models/Book.cs` | `app/Models/Book.php` |
| `src/ReadLog.Web/Models/ReadEntry.cs` | `app/Models/ReadEntry.php` |
| `src/ReadLog.Web/Models/ApplicationUser.cs` | `app/Models/User.php` |
| `src/ReadLog.Web/Models/Format.cs` | `app/Enums/Format.php` |
| `src/ReadLog.Web/Models/FormatDisplay.cs` | the same file, `app/Enums/Format.php` |
| `src/ReadLog.Web/Models/ICreatedAt.cs` plus the `SaveChanges` override | Eloquent timestamps plus `const UPDATED_AT = null` |
| `src/ReadLog.Web/Data/ApplicationDbContext.cs` | `database/migrations/2026_08_18_100000_create_books_table.php` and `..._create_read_entries_table.php` |
| `DateOnly` plus its EF Core converter | `app/Casts/DateOnly.php` |

`Models/FormatDisplay.cs` exists in C# only because an enum cannot carry
behaviour, so the display strings live on a static extension class. A PHP backed
enum can carry methods, so `label()`, `pluralLabel()` and `icon()` sit on the enum
itself and one file disappears.

### Application services

| readlog-dotnet | here |
| --- | --- |
| `src/ReadLog.Web/Services/ReadLogService.cs` | `app/Services/ReadLogService.php` |
| `src/ReadLog.Web/Services/BookSearchService.cs` | `app/Services/BookSearchService.php` |
| `src/ReadLog.Web/Services/BookDetailsService.cs` | `app/Services/BookDetailsService.php` |
| `src/ReadLog.Web/Services/BookDescriptionSanitizer.cs` | `app/Services/BookDescriptionSanitizer.php` |
| `src/ReadLog.Web/Services/External/OpenLibraryClient.cs` | `app/Services/External/OpenLibraryClient.php` |
| `src/ReadLog.Web/Services/External/GoogleBooksClient.cs` | `app/Services/External/GoogleBooksClient.php` |
| `src/ReadLog.Web/Services/DuplicateReadEntryException.cs` | `app/Exceptions/DuplicateReadEntryException.php` |
| `IReadLogService`, `IBookSearchService`, `IOpenLibraryClient`, `IGoogleBooksClient` | no counterpart |

The four interfaces are gone. In .NET they earn their place: they let the tests
substitute a stub and they are what the container registration binds to. Neither
reason survives the move. Laravel's container resolves a concrete class with
type-hinted constructor dependencies without being told anything, and the tests
here run against a real in-memory SQLite database and `Http::fake()` rather than
hand-written stubs. An interface with one implementation and no caller that cares
which is not abstraction, it is a file.

That change had a side effect I did not expect and would defend anyway. The .NET
`BookSearchServiceTests.cs` stubs `IOpenLibraryClient`, so it tests the merge
logic against invented `BookSearchResult` objects and never touches the code that
turns Open Library's JSON into them. Faking at the HTTP layer instead means
`tests/Feature/Services/BookSearchServiceTest.php` covers the provider response
shapes and the merge in the same cases.

### HTTP layer

| readlog-dotnet | here |
| --- | --- |
| `src/ReadLog.Web/Pages/Index.cshtml.cs` | `app/Http/Controllers/FeedController.php` |
| `src/ReadLog.Web/Pages/Library.cshtml.cs` | `app/Http/Controllers/LibraryController.php` |
| `src/ReadLog.Web/Pages/Library/Edit.cshtml.cs` | `app/Http/Controllers/ReadEntryController.php` |
| `src/ReadLog.Web/Pages/Log.cshtml.cs` | `app/Http/Controllers/LogController.php` |
| `src/ReadLog.Web/Pages/Account.cshtml.cs` | `app/Http/Controllers/AccountController.php` |
| `src/ReadLog.Web/Pages/Book.cshtml.cs` | `app/Http/Controllers/BookController.php` |
| `src/ReadLog.Web/Dtos/LogBookRequest.cs` | `app/Http/Requests/LogBookRequest.php` plus `app/Support/LogBookData.php` |
| `src/ReadLog.Web/Dtos/UpdateReadEntryRequest.cs` | `app/Http/Requests/UpdateReadEntryRequest.php` plus `app/Support/UpdateReadEntryData.php` |
| `src/ReadLog.Web/Dtos/LibraryDtos.cs` | `app/Support/LibraryEntry.php`, `BookSummary.php`, `PublicRead.php`, `AccountStats.php` |
| `src/ReadLog.Web/Validation/NotInFutureAttribute.cs` | the rule string `before_or_equal:today` |
| `[Authorize]` | `app/Http/Middleware/RequireDemoUser.php` on a route group |
| `src/ReadLog.Web/Auth/ClaimsPrincipalExtensions.cs` | `app/Services/CurrentUser.php` |
| the `app.Use(...)` header block in `Program.cs` | `app/Http/Middleware/SecurityHeaders.php` |
| `ForwardedHeadersOptions` in `Program.cs` (KnownProxies cleared) | `config/trustedproxy.php`, `TRUSTED_PROXIES` |
| `/health` for the platform probe | `/up`, plus `php artisan readlog:smoke` for a person |
| folder-based routing plus `app.MapRazorPages()` | `routes/web.php` |
| `builder.Services.Add*` in `Program.cs` | `app/Providers/AppServiceProvider.php`, which is nearly empty |

The .NET DTO is one class carrying both the shape and the validation, as
attributes on its properties. Laravel splits that: the rules live on a
`FormRequest` in the HTTP layer, and what reaches the service is a plain readonly
value object with nothing attached. The split is more files and a better boundary.
`ReadLogService` cannot see a request, a session or a container, which is the same
property the .NET service gets by taking `userId` as a parameter.

`NotInFutureAttribute.cs` is 28 lines of C# implementing a custom
`ValidationAttribute`. Its counterpart is the string `before_or_equal:today`.

### Views

| readlog-dotnet | here |
| --- | --- |
| `Pages/Shared/_Layout.cshtml` | `resources/views/layouts/app.blade.php` |
| `Pages/Shared/_Stars.cshtml` | `resources/views/partials/stars.blade.php` |
| `Pages/Shared/_LoginPartial.cshtml` | `resources/views/partials/demo-user.blade.php` |
| `Pages/Index.cshtml` | `resources/views/feed.blade.php` |
| `Pages/Library.cshtml` | `resources/views/library.blade.php` |
| `Pages/Library/Edit.cshtml` | `resources/views/entries/edit.blade.php` |
| `Pages/Log.cshtml` | `resources/views/log.blade.php` |
| `Pages/Account.cshtml` | `resources/views/account.blade.php` |
| `Pages/Book.cshtml` | `resources/views/book.blade.php` |
| `Pages/Error.cshtml` with a status-code branch | `resources/views/errors/404.blade.php` and `500.blade.php` |
| `@Html.Raw(...)` | `{!! !!}` |
| `@RenderBody` / `@RenderSectionAsync` | `@yield` / `@section` |
| `<partial name="_Stars" />` | `@include('partials.stars')` |
| the antiforgery token, injected automatically | `@csrf`, written by hand |
| `wwwroot/js/site.js` | `public/js/site.js`, almost verbatim |
| `wwwroot/css/site.css` themed over Bootstrap | `public/css/site.css`, standalone |

### Tests

| readlog-dotnet | here |
| --- | --- |
| xUnit `[Fact]` / `[Theory]` | Pest `it(...)` / `->with([...])` |
| `SqliteTestDatabase` helper | the `RefreshDatabase` trait |
| `StubHttpMessageHandler` | `Http::fake()` |
| `WebApplicationFactory<Program>` | Laravel's built-in `$this->get(...)` |
| `WebTestClient.RegisterAsync` | the `actingAsReader()` helper in `tests/Pest.php` |
| a shared base class per test collection | `pest()->extend(...)->in('Feature')` |
| nothing | `LiveProviderTest`, three tests that call the real APIs |

## What did not translate cleanly

### Active Record versus data mapper

This is the difference that shows up in the most places, and not in the way I
expected. I expected trouble from `$book->save()`. There was none. Saving an
entity through the entity is comfortable and the code reads well.

What actually bites is that **nothing connects the model to the schema**.

In EF Core, `src/ReadLog.Web/Models/Book.cs` and the fluent configuration in
`ApplicationDbContext.OnModelCreating` together *are* the schema, and
`dotnet ef migrations add` diffs the C# model against the last snapshot to
generate the migration. The model is the source of truth and the migration is
derived from it. If I rename a property, the compiler finds every use, and the
next migration reflects the rename.

Here, `database/migrations/2026_08_18_100000_create_books_table.php` is the source
of truth and `app/Models/Book.php` knows nothing about it. Nothing checks that the
two agree. A typo in a `$fillable` entry is not an error, it is a column that
silently stops being writable. A column removed from a migration is not an error,
it is a property that silently starts returning null. There is no build step to
notice, because there is no build step.

I hit exactly that. `DemoLibrarySeeder::reader()` set `email_verified_at` through
`User::updateOrCreate`. That column is not on the `User` mass-assignment
allowlist, so `fill()` dropped it without a word. It appeared to work only because
`artisan db:seed` wraps seeders in `Model::unguarded()`. In C# the equivalent
mistake, an object initialiser setting a property that should not be settable, is
a compile error. Here it was a silent no-op that a test had to catch. The seeder
now assigns properties directly and there is a test that runs it with guards on.

The second consequence is that the change tracker is gone, and that turns out to
be mostly a relief. The .NET tests open and close a `DbContext` around each step
so they can prove a value really reached the database rather than sitting in the
change tracker. In Eloquent a save is a statement, so `->fresh()` is enough and
the tests are shorter.

### PHP has no date-only type

`ReadEntry.FinishedAt` is a `DateOnly` in C#. EF Core has a converter for it and
writes `2024-03-03`. Straightforward.

PHP has no equivalent, and Laravel's built-in `date` cast is not a substitute. It
reads a column back as a Carbon instance at midnight, which looks right, but it
still *writes* the connection's full datetime format. The column ends up holding
`2024-03-03 00:00:00`.

That broke two things at once. The unique `(user_id, book_id, finished_at)` index
has to be matched against the `2024-03-03` that an HTML date input produces, and a
database written by this app should stay readable by readlog-dotnet, which stores
the bare date.

`app/Casts/DateOnly.php` is the answer: 55 lines to get back what one C# type gave
for free. It reads back a `CarbonImmutable`, because `DateOnly` is a value type in
.NET and an immutable instance stops `$entry->finished_at->addDay()` quietly
mutating the model's attribute.

I did not find this by reading documentation. I found it because running the
seeder twice blew up on the unique index: `firstOrNew(['finished_at' => '2026-08-06'])`
did not match the stored `2026-08-06 00:00:00`.

### The cache stores bytes, not objects

`IMemoryCache` holds a live object reference. Caching a `List<PublicReadDto>` in
`src/ReadLog.Web/Services/ReadLogService.cs` costs nothing and gives back the same
instances.

Every Laravel cache store except the in-process array one serialises. On top of
that, Laravel 13 ships `config/cache.php` with `'serializable_classes' => false`,
which means `unserialize()` runs with `allowed_classes: false` and **any** object
comes back as `__PHP_Incomplete_Class`. It is a deliberate hardening measure
against gadget chains if `APP_KEY` leaks, and it is a good default.

The first version of `getRecentPublicReads()` cached a collection of DTOs. It
passed all twenty service tests and returned a 500 on every second request in a
browser. It passed the tests because `phpunit.xml` sets `CACHE_STORE=array`, and
the array store does not serialise, so the test environment could not see the bug
the production configuration had.

The fix was to cache plain arrays of scalars and rebuild the objects on read, in
both `ReadLogService::getRecentPublicReads()` and `BookDetailsService`, keeping
Laravel's secure default rather than widening an allowlist to suit this app.
`tests/Feature/Services/PublicFeedCacheTest.php` forces a serialising store. I
re-introduced the bug to confirm those three tests fail with the production error
while the other twenty still pass.

### Concurrency, and the absence of await

`src/ReadLog.Web/Services/BookSearchService.cs` starts two tasks and awaits both:

```csharp
var results = await Task.WhenAll(openLibraryTask, googleTask);
```

PHP has no `await` and no threads. Laravel's answer is `Http::pool`, which hands
the requests to Guzzle's `curl_multi` handle and blocks until all of them come
back. The wall-clock behaviour matches, two requests in flight rather than one
after the other.

The shape does not match, and the difference leaked into the design. A pool needs
every request handed over before any response exists, so a client method that
fetches and maps in one go cannot be pooled. Both clients had to be split into
`requestSearch()` and `parseSearch()`. That is the visible cost of not having
`await`, and it is the only structural change the port forced on those classes.

Cancellation is simply gone. Every method in the .NET service takes a
`CancellationToken`, and `BookSearchService.cs` is careful to let a
caller-initiated cancel propagate rather than degrade to empty results. A PHP
request has nothing to cancel from. Those parameters have no counterpart and were
dropped rather than faked with a parameter nobody reads.

### Blade is not Razor, in one specific way

Blade and Razor are close enough that most of the port was mechanical. `{{ }}`
escapes like `@`, `{!! !!}` is `@Html.Raw`, `@yield` plus `@section` is
`@RenderBody` plus `@RenderSectionAsync`.

One rule has no Razor counterpart. **A Blade directive is only a directive when
it is not preceded by a word character.** Razor transitions on `@` anywhere, so
`Pages/Library/Edit.cshtml` can write:

```razor
Editing your entry@(string.IsNullOrEmpty(Model.Book.Author) ? "" : $" · {Model.Book.Author}")
```

The direct Blade translation, `entry@if (...)`, compiles to the literal text
`entry@if`, and then the matching `@endif` fails with `syntax error, unexpected
token "endif"` pointing at a compiled file. The error names nothing that appears
in the source. `resources/views/entries/edit.blade.php` now uses a single echo,
which is what the Razor original was doing anyway.

### POST-redirect-GET instead of re-rendering

Razor Pages returns `Page()` when a post fails validation, and the response is a
200 with the errors in the body. `UiPagesTests.cs` asserts exactly that.

Laravel's entire validation story is built on the redirect: `old()`, the `$errors`
bag and the session flash all assume the browser comes back with a fresh GET.
Fighting that to return a 200 means hand-rolling what the framework already does.
The behaviour a user sees is identical. The tests changed shape, from a status
code and a string match to `assertRedirect()` plus `assertSessionHasErrors()`.

### Two guarantees that did not survive

**The check constraint.** `ApplicationDbContext.OnModelCreating` declares
`CK_ReadEntry_Rating`, so the database itself refuses a rating outside 0 to 5.
Laravel's schema builder has no check-constraint API, and SQLite cannot add one to
an existing table, so the bound moved to request validation. This is a real loss:
a raw SQL insert can now store `rating = 9`. There is a test in
`tests/Feature/Database/SchemaConstraintsTest.php` that asserts the gap rather
than a comment that hides it, and it is the test that should fail and be rewritten
if a trigger or a raw `CREATE TABLE` ever restores the guarantee.

**Nullable reference types.** readlog-dotnet sets `WarningsAsErrors=nullable` in
`Directory.Build.props`, so a possible null dereference does not compile. PHP 8.4
has typed properties and nullable type hints, which catch a lot, but they are
checked at runtime and only where a type is declared. The equivalent guarantee
needs a separate static analyser, and this port now runs one: PHPStan (through
Larastan) at level 6, in CI. Turning it on after the fact was instructive. Of the
twenty-one findings, one was a genuine dead branch (a null check on a NOT NULL
column) and the rest were the type information Eloquent never carries: an
analyser cannot know that `$entry->format` is an enum and `$entry->finished_at`
a date unless the model says so in `@property` lines, which is the PHP stand-in
for the CLR property types EF Core gets for free.

### Two libraries that behave differently

Ganss.Xss, which `src/ReadLog.Web/Services/BookDescriptionSanitizer.cs` uses,
keeps the *text* of a disallowed tag and drops only the tag.
symfony/html-sanitizer defaults to dropping the element and everything inside it.
The first version of `app/Services/BookDescriptionSanitizer.php` therefore turned
a description wrapped in an unknown tag into an empty string. No error, no
warning, just a book with no blurb.

`defaultAction(HtmlSanitizerAction::Block)` restores the Ganss behaviour, and the
explicit `dropElement` calls for `script`, `style`, `iframe`, `object`, `embed`
and `form` are what make that safe: those are the elements whose text content must
not survive either.

## What surprised a .NET developer

**No compile step changes how you work.** I knew this in the abstract. In practice
it means a typo lives until something executes that line, and "something executes
that line" often means a browser, not a test. Three of the bugs in this port
(`entry@if`, the cache serialisation, the sanitiser) were invisible to the type
system because there is no type system running before the code does. The
compensation is a fast test suite, and I leaned on it much harder here than I do
in C#.

**The container barely needs configuring.** `Program.cs` has seventeen
`builder.Services.Add*` registrations.
`app/Providers/AppServiceProvider.php` has none, because
Laravel resolves any concrete class whose constructor dependencies are type-hinted
without being told it exists. That is a real reduction in ceremony. It is also one
less place to look when you want to know what the application is made of.

**Facades looked wrong and stopped looking wrong.** `Cache::remember(...)` and
`Http::pool(...)` read as static calls to global state, which is exactly what a
.NET developer is trained to distrust. They are proxies to container-resolved
instances, which is why `Http::fake()` works at all. I still prefer constructor
injection and used it for everything I wrote, but the facades are not the
anti-pattern they look like.

**Attributes on properties are not the idiom.** C# hangs validation, display names
and binding rules directly on the DTO properties. Laravel keeps them in a method
on a separate request class. It felt like a step backwards for about an hour, and
then it stopped, because the rules being data rather than attributes means they
can be composed and conditioned without reflection.

**Migrations being hand-written is a downgrade I did not expect to mind.** Writing
`$table->string('title')` after having written `public required string Title` feels
like saying it twice. It is saying it twice. The upside is that the migration says
exactly what will run, with no snapshot file and no diffing step that can disagree
with the model.

## What each side did better

### Laravel

- **The test story.** `RefreshDatabase`, `Http::fake()`, `$this->get('/library')`
  against the real app, `assertSessionHasErrors`. Every one of those exists in
  .NET, but as something you assemble: `readlog-dotnet` ships
  `SqliteTestDatabase.cs`, `StubHttpMessageHandler.cs`, `ReadLogAppFactory.cs`,
  `WebTestClient.cs` and `HtmlFormHelper.cs`, five infrastructure files, to reach
  the same place. Here that is zero files.
- **`Http::fake()` and `preventStrayRequests()` together.** Faking at the HTTP
  boundary rather than at an interface means the tests cover the JSON parsing too.
  `preventStrayRequests()` has no .NET equivalent because .NET does not need one,
  but it caught something real here.
- **Less ceremony per feature.** The routing table, the container, the request
  validation and the view layer all needed less code than their counterparts, and
  the reduction is not obtained by hiding anything important.
- **Blade partials with explicit arguments.** `@include('partials.cover', ['url' =>
  ..., 'size' => 'sm'])` collapsed five repeated cover-or-placeholder blocks in the
  Razor views into one file.

### .NET

- **The compiler.** Every single bug listed under pain points above would have been
  a compile error or a nullable warning in C#. That is not a small thing, and no
  amount of test coverage is the same guarantee.
- **`DateOnly`, `TimeSpan`, `decimal`.** Types that mean something, that the ORM
  understands, and that do not need a 55-line cast class.
- **`IMemoryCache`.** An in-process cache that stores references and never
  serialises is both faster and free of a whole category of bug.
- **Async and cancellation.** `Task.WhenAll` and `CancellationToken` are ordinary,
  composable and everywhere. `Http::pool` covers one specific case well and
  nothing else, and cancellation has no answer at all.
- **Generated migrations.** `dotnet ef migrations add` deriving the schema from the
  model keeps one source of truth. Hand-written migrations keep two, and nothing
  checks that they agree.
- **The database-level check constraint.** `HasCheckConstraint` is one line and it
  is enforced by the storage engine rather than by remembering to validate.

## What is not ported, and why

- **Authentication.** ASP.NET Core Identity, local accounts and the optional Google
  login are all absent. Version 1 scope was books, reading entries, search and the
  multi-source lookup. Per-user ownership is still modelled and tested, because
  "your library" and "not found rather than forbidden" are behaviours, not
  authentication. `app/Services/CurrentUser.php` is a session-backed demo stand-in
  that collapses to `auth()->id()` the day real auth lands.
- **Docker, Azure App Service, forwarded headers, HSTS, data-protection keys.** All
  deployment concerns for an app that is documented as running locally.
- **Health checks.** Laravel ships `/up` and the source's `/health` was for the
  Azure probe.
- The full list, with reasoning, is in [DECISIONS.md](DECISIONS.md).

## How AI assistance was used, and where it was wrong

The whole migration was done with an AI coding agent (Claude Code) driving, with
the brief, the review and the judgement calls on my side. Being specific about
where that helped and where it produced wrong output is more useful than a
disclaimer, so here is the honest version.

**What it was good at.** Reading readlog-dotnet end to end and holding the whole
thing in view while writing the Laravel equivalent. Mechanical translation of the
Razor views to Blade. Writing the first draft of the ported test suite, which is
mostly a transliteration of `ReadLogServiceTests.cs` and benefits from patience
rather than insight. Producing the mapping tables above.

**Where the output was wrong, and how it was caught.** Every item here is a real
failure from this run, in order.

1. **`'finished_at' => 'date'` looked correct and was not.** The obvious Eloquent
   cast reads a date back correctly, which is what makes it convincing. It writes a
   full datetime. Caught by the seeder failing its own idempotency test, not by
   review. Fixed with `app/Casts/DateOnly.php`.

2. **Caching DTOs in the public feed.** A direct translation of the .NET
   `IMemoryCache` usage, and wrong for a reason specific to Laravel 13's default
   configuration. It passed the entire test suite. Caught by loading the home page
   twice in a browser and getting a 500 the second time.

3. **`entry@if (...)` in the edit view.** A literal translation of the Razor line.
   Caught by a 500 on `/library/1/edit` during a manual pass over every route. The
   error message pointed at a compiled file and named nothing in the source.

4. **The seeder depending on `Model::unguarded()`.** `User::updateOrCreate` with
   `email_verified_at` in the values, which `fill()` silently drops. It worked
   because `artisan db:seed` unguards. Caught by asking "why does this work when
   the column is not fillable" during self-review, then confirmed by running
   `fill()` directly.

5. **A performance optimisation that introduced a correctness bug.** Binding
   `CurrentUser` as `scoped` and memoising the resolved reader. The class holds a
   `Session`, so a binding that outlives one request hands the next request the
   previous session. Under `php artisan serve` every request is a fresh process, so
   the bug is invisible. The test suite handles many requests in one process and
   failed immediately, showing one reader's library to another. Reverted, with the
   reasoning left on `CurrentUser::get()` so nobody repeats it.

6. **The Google API key written to the log.** `Log::warning(..., $e->getMessage())`
   is a faithful translation of the .NET line. In .NET, `HttpRequestException` does
   not carry the URI. In PHP, Guzzle puts the full request URL in the message, and
   Google Books only accepts its key in the query string. Caught by deliberately
   triggering a connection failure and reading the output, which is not something
   the source could have prompted, because the source does not have the problem.

7. **The test suite quietly going to the network.** Wiring `BookSearchService` into
   the log page turned several phase 2 tests into real calls to openlibrary.org.
   They still passed, because the search degrades when a provider is unreachable.
   The only signal was the suite going from 2.8 seconds to 13.
   `Http::preventStrayRequests()` now makes it an error.

8. **The sanitiser deleting content.** An allowlist config that looked complete and
   silently dropped the text of any element not on it, because Symfony's default
   action differs from Ganss.Xss's. Caught by a test written on the hunch that the
   two libraries might not agree.

9. **Two tests that were wrong rather than the code.** One asserted that no reader
   is ever named on the home page, which the demo reader switcher in the navigation
   bar makes false; the real guarantee is about the feed projection, so the
   assertion is now scoped to `<main>` and backed by a service test asserting
   `PublicRead`'s shape. The other expected `route()` to encode a space as `+`.
   Both were corrected rather than deleted, which is the more useful outcome: a
   test that was wrong about the boundary is a sign the boundary was not clear.

The pattern in that list is worth naming. The failures cluster where the two
ecosystems differ *silently*: same concept, same-looking API, different behaviour,
no error at the point of the mistake. Type errors and missing methods were caught
instantly and cost nothing. What cost time was the code that ran, did something
plausible, and was wrong. A faithful port is exactly the situation that produces
those, because the .NET original is a correct and confident guide right up until
the moment it is not.
