<?php

use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| readlog:smoke. The HTTP half is faked; the database, migration, catalogue and
| provider checks run for real against the test database.
*/

function fakeHealthyApp(string $base = 'http://localhost:8000'): void
{
    Http::fake([
        "{$base}/up" => Http::response('ok', 200),
        "{$base}/" => Http::response('<h1>Recently Read</h1>', 200),
    ]);
}

it('passes on a healthy, seeded instance', function () {
    fakeHealthyApp();
    ReadEntry::factory()->for(User::factory())->create();
    config()->set('services.google_books.api_key', 'k');

    $this->artisan('readlog:smoke')
        ->expectsOutputToContain('Health route')
        ->expectsOutputToContain('All checks passed.')
        ->assertExitCode(0);
});

it('warns rather than fails when there is no Google Books key', function () {
    fakeHealthyApp();
    ReadEntry::factory()->for(User::factory())->create();
    config()->set('services.google_books.api_key', null);

    $this->artisan('readlog:smoke')
        ->expectsOutputToContain('WARN')
        ->expectsOutputToContain('1 warning(s)')
        ->assertExitCode(0);
});

it('fails when the health route does not answer 200', function () {
    Http::fake([
        'http://localhost:8000/up' => Http::response('nope', 503),
        'http://localhost:8000/' => Http::response('<h1>Recently Read</h1>', 200),
    ]);
    ReadEntry::factory()->for(User::factory())->create();

    // One substring per output line: the assertion helper consumes each write
    // against the first matching expectation only, so 'FAIL' and 'returned 503'
    // on the same table row cannot both be asserted.
    $this->artisan('readlog:smoke')
        ->expectsOutputToContain('returned 503')
        ->expectsOutputToContain('1 check(s) failed.')
        ->assertExitCode(1);
});

it('fails when the URL is unreachable at all', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    ReadEntry::factory()->for(User::factory())->create();

    $this->artisan('readlog:smoke')
        ->expectsOutputToContain('Connection refused')
        ->assertExitCode(1);
});

it('checks the URL it is given rather than APP_URL', function () {
    fakeHealthyApp('https://demo.trycloudflare.com');
    ReadEntry::factory()->for(User::factory())->create();

    $this->artisan('readlog:smoke', ['--url' => 'https://demo.trycloudflare.com/'])
        ->expectsOutputToContain('GET https://demo.trycloudflare.com/up returned 200')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://demo.trycloudflare.com/up');
});

it('fails when the catalogue is empty', function () {
    fakeHealthyApp();

    expect(Book::count())->toBe(0);

    $this->artisan('readlog:smoke')
        ->expectsOutputToContain('no books; run php artisan db:seed')
        ->assertExitCode(1);
});

it('can skip HTTP and still verify the database side', function () {
    Http::fake();
    ReadEntry::factory()->for(User::factory())->create();

    $this->artisan('readlog:smoke', ['--no-http' => true])
        ->doesntExpectOutputToContain('Health route')
        ->expectsOutputToContain('none pending')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('does not print a Google API key that appears in an error message', function () {
    config()->set('services.google_books.api_key', 'SUPER-SECRET');
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 6 for http://localhost:8000/up?key=SUPER-SECRET'
    ));
    ReadEntry::factory()->for(User::factory())->create();

    $this->artisan('readlog:smoke')
        ->doesntExpectOutputToContain('SUPER-SECRET')
        ->expectsOutputToContain('key=REDACTED')
        ->assertExitCode(1);
});
