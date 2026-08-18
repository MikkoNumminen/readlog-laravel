<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\ReadEntry;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a running instance and prints a short pass/fail table.
 *
 *     php artisan readlog:smoke                      # against APP_URL
 *     php artisan readlog:smoke --url=https://x.trycloudflare.com
 *     php artisan readlog:smoke --no-http            # database and config only
 *
 * The HTTP checks go to whatever URL is given, so run from inside the compose
 * app container against the public tunnel URL this is a genuine end-to-end
 * check: out through the tunnel and back in through nginx. The database and
 * config checks read the environment the command itself runs in, so they
 * describe the instance the command is run on, not the one behind the URL. For
 * a single local instance those are the same thing.
 *
 * .NET counterpart: none. readlog-dotnet exposes /health for a platform probe and
 * has no operator-facing check; the nearest thing is running the integration
 * suite. This exists because the app is meant to be exposed on demand for a
 * demo, and "is it actually up, from outside, right now" deserves one command.
 *
 * Exit code is 1 if any check FAILs. WARN never fails the run: it marks a state
 * the app tolerates by design (no Google Books key) that the person about to
 * demo it may still want to know about.
 */
class SmokeCheck extends Command
{
    protected $signature = 'readlog:smoke
        {--url= : Base URL to check over HTTP (default: APP_URL)}
        {--no-http : Skip the HTTP checks; verify database and configuration only}
        {--timeout=10 : Seconds to wait for each HTTP request}';

    protected $description = 'Verify a running ReadLog instance: health route, home page, database, migrations, demo data, providers';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(Migrator $migrator): int
    {
        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/');

        if (! $this->option('no-http')) {
            $this->checkHealthRoute($url);
            $this->checkHomePage($url);
        }

        $this->checkDatabase();
        $this->checkMigrations($migrator);
        $this->checkDemoData();
        $this->checkProviders();

        $this->newLine();
        $this->table(['Check', 'Result', 'Detail'], $this->rows);

        $failed = collect($this->rows)->where(1, 'FAIL')->count();
        $warned = collect($this->rows)->where(1, 'WARN')->count();

        $this->newLine();
        if ($failed > 0) {
            $this->components->error("{$failed} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info($warned > 0
            ? "All checks passed, {$warned} warning(s)."
            : 'All checks passed.');

        return self::SUCCESS;
    }

    private function checkHealthRoute(string $url): void
    {
        try {
            $response = Http::timeout($this->timeout())->get("{$url}/up");

            $response->ok()
                ? $this->passed('Health route', "GET {$url}/up returned 200")
                : $this->failed('Health route', "GET {$url}/up returned {$response->status()}");
        } catch (Throwable $e) {
            $this->failed('Health route', "GET {$url}/up failed: ".$this->brief($e));
        }
    }

    private function checkHomePage(string $url): void
    {
        try {
            $response = Http::timeout($this->timeout())->get("{$url}/");

            if (! $response->ok()) {
                $this->failed('Home page', "GET {$url}/ returned {$response->status()}");

                return;
            }

            str_contains($response->body(), 'Recently Read')
                ? $this->passed('Home page', 'renders the feed')
                : $this->failed('Home page', 'returned 200 but does not look like the ReadLog feed');
        } catch (Throwable $e) {
            $this->failed('Home page', "GET {$url}/ failed: ".$this->brief($e));
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::select('select 1');
            $connection = DB::connection();

            $this->passed('Database', sprintf('%s, %s', $connection->getDriverName(), $connection->getDatabaseName()));
        } catch (Throwable $e) {
            $this->failed('Database', 'not reachable: '.$this->brief($e));
        }
    }

    private function checkMigrations(Migrator $migrator): void
    {
        try {
            if (! $migrator->repositoryExists()) {
                $this->failed('Migrations', 'migrations table missing; run php artisan migrate');

                return;
            }

            $ran = $migrator->getRepository()->getRan();
            $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
            $pending = array_diff(array_keys($files), $ran);

            $pending === []
                ? $this->passed('Migrations', count($ran).' applied, none pending')
                : $this->failed('Migrations', count($pending).' pending: '.implode(', ', $pending));
        } catch (Throwable $e) {
            $this->failed('Migrations', 'could not be checked: '.$this->brief($e));
        }
    }

    private function checkDemoData(): void
    {
        try {
            $books = Book::count();
            $entries = ReadEntry::count();

            $books > 0
                ? $this->passed('Catalogue', "{$books} books, {$entries} reading entries")
                : $this->failed('Catalogue', 'no books; run php artisan db:seed');
        } catch (Throwable $e) {
            $this->failed('Catalogue', 'could not be counted: '.$this->brief($e));
        }
    }

    private function checkProviders(): void
    {
        $openLibrary = (string) config('services.open_library.base_url');
        $openLibrary !== ''
            ? $this->passed('Open Library', $openLibrary)
            : $this->failed('Open Library', 'services.open_library.base_url is empty');

        $key = config('services.google_books.api_key');
        is_string($key) && trim($key) !== ''
            ? $this->passed('Google Books', 'API key set')
            : $this->warned('Google Books', 'no API key: search uses Open Library alone, book pages show no details');
    }

    private function passed(string $check, string $detail): void
    {
        $this->rows[] = [$check, 'PASS', $detail];
    }

    private function warned(string $check, string $detail): void
    {
        $this->rows[] = [$check, 'WARN', $detail];
    }

    private function failed(string $check, string $detail): void
    {
        $this->rows[] = [$check, 'FAIL', $detail];
    }

    private function timeout(): int
    {
        return max(1, (int) $this->option('timeout'));
    }

    /** One line of an exception, without a stack trace or a credential-bearing URL. */
    private function brief(Throwable $e): string
    {
        $message = preg_replace('/([?&]key=)[^&\s]+/i', '$1REDACTED', $e->getMessage()) ?? $e->getMessage();

        // strtok() returns false on an empty message, and PHP would also treat a
        // message of exactly "0" as empty under ?:, so the check is explicit.
        $firstLine = trim((string) strtok($message, "\n"));

        return $firstLine !== '' ? $firstLine : get_class($e);
    }
}
