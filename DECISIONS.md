# Decisions

Every call made during the migration that a reviewer could reasonably have made
differently, with one line of reasoning each. Appended to as the work proceeds,
newest phase last. The detailed write-up lives in
[MIGRATION.md](MIGRATION.md); this file is the running log.

Source of truth for behaviour: [readlog-dotnet](https://github.com/MikkoNumminen/readlog-dotnet),
read from a local checkout at `D:\koodaamista\Readlog-csharp`.

## Setup

| # | Decision | Reasoning |
| --- | --- | --- |
| 1 | Treated `D:\koodaamista\Readlog-csharp` as the source repo | The brief named a "koodailua" folder and a `readlog-dotnet` project; the only matching checkout on the machine is `koodaamista\Readlog-csharp`, whose remote is `MikkoNumminen/readlog-dotnet`. Same repo, different local folder name. |
| 2 | Installed PHP 8.4.24 and Composer 2.10.2 | Neither was on the machine. PHP 8.4 is the newest release Laravel 13 supports across the board, and it is available as a winget package, so the install is reproducible. |
| 3 | Laravel 13.26.0 | Latest stable at the time of the migration, which is what the brief asks for. |
| 4 | Pest 4 as the test runner, `phpunit.xml` kept | The brief specifies Pest. Pest 4 runs on PHPUnit underneath and still reads `phpunit.xml`, so the config file stays where a PHP developer expects it. |
| 5 | Deleted the Vite front end (`package.json`, `vite.config.js`, `resources/css`, `resources/js`) | The brief rules out a JS framework and asks for an app that runs with `php artisan serve`. Keeping Vite would put `npm install && npm run build` between a clone and a rendered page. |
| 6 | Public GitHub repo `MikkoNumminen/readlog-laravel` | The brief frames this as a public portfolio project whose process is the product, and it asks for pull requests, which need a remote. |

## Phase 1: skeleton and domain

| # | Decision | Reasoning |
| --- | --- | --- |
| 7 | No authentication in version 1 | The brief lists version 1 scope as four items and says "this only"; none of them is auth, and it asks for auth to be recorded in TODO.md if the source has it and it was skipped. ASP.NET Core Identity has no small Laravel equivalent that avoids npm, so porting it properly is its own phase. |
| 8 | Kept per-user ownership anyway, with a session-backed demo user | Read entries belong to a user in the source, and the "your library" and "not found rather than forbidden" rules are part of the behaviour being ported. Dropping users would have quietly removed real logic. |
| 9 | `user_id` is a bigint foreign key, not a GUID string | The GUID string key comes from `IdentityUser`, which is not being ported. Laravel's stock `users` table is bigint-keyed, and matching the framework beats matching a column type whose reason for existing has been removed. |
| 10 | No `CK_ReadEntry_Rating` check constraint | Laravel's schema builder has no check-constraint API, and SQLite cannot add one to an existing table. The 0 to 5 bound moves to request validation. This is a real loss of a database-level guarantee and is pinned by a test that documents the gap. |
| 11 | Hand-written `App\Casts\DateOnly` instead of Laravel's `date` cast | PHP has no `DateOnly`. Laravel's `date` cast reads back as midnight but writes a full datetime string, which would break the unique `(user_id, book_id, finished_at)` index against the `YYYY-MM-DD` value an HTML date input produces, and would stop readlog-dotnet reading the same file. |
| 12 | `created_at` only, no `updated_at` | The .NET entities implement `ICreatedAt` and have no modified-at column. `const UPDATED_AT = null` tells Eloquent to stop looking for the column it would otherwise assume. |
| 13 | Format display helpers live on the enum | C# needs `Models/FormatDisplay.cs` as a separate static extension class because an enum cannot carry behaviour. A PHP backed enum can, so `label()`, `pluralLabel()` and `icon()` go where they belong. |
| 14 | Seed data carries real Open Library work keys and cover ids | `open_library_id` is the natural key the log flow uses for find-or-create. Made-up keys would let a seeded book and a searched book duplicate each other, which would misrepresent how the app behaves. |
| 15 | Added model factories, which the source has no counterpart for | The .NET tests build entities inline. Laravel test code leans on factories hard enough that not having them would have made every later test noisier. |

### Added during the phase 1 self-review

| # | Decision | Reasoning |
| --- | --- | --- |
| 16 | Added `.github/workflows/ci.yml` | readlog-dotnet gates every PR on build plus tests. This project's evidence is its pull requests, so they should carry the same green check. PHP has no compile step, so the workflow migrates a clean database as well as running the suite. |
| 17 | Trimmed AWS, Redis, Memcached and Vite entries out of `.env.example` | The brief rules out anything needing hosting or a paid service. Every one of those keys has a working default in `config/`, so listing them only suggests the app might use them. |
| 18 | Seeder assigns user properties directly instead of via `updateOrCreate` | Review finding: `email_verified_at` is not on the mass-assignment allowlist, so `fill()` drops it silently. It worked only because `artisan db:seed` runs seeders inside `Model::unguarded()`. A seeder should not depend on the caller having switched the guard off, so there is now a test that runs it with guards on. |
