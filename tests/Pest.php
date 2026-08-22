<?php

use App\Models\User;
use App\Services\CurrentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
|
| .NET counterpart: roughly xUnit's shared base class plus collection fixtures.
| `uses(...)` binds a base TestCase (and traits) to whole directories, instead of
| every test class deriving from one explicitly.
|
| RefreshDatabase plays the part of the .NET tests' SqliteTestDatabase helper: it
| migrates a fresh schema and wraps each test in a transaction that is rolled back
| afterwards, so tests never see each other's rows. It is bound to Feature only;
| Unit tests here are pure and should not pay for a database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // No test reaches the network unless it says so.
        //
        // This is not belt and braces. The moment BookSearchService was wired into
        // the log page, the phase 2 page tests started calling openlibrary.org for
        // real: they still passed, and the suite went from 2.8 to 13 seconds, which
        // is the only reason anyone noticed. preventStrayRequests turns an unfaked
        // outbound request into a failure naming the URL.
        //
        // .NET counterpart: the source injects a StubHttpMessageHandler per test, so
        // a client with no stub simply has nowhere to send anything. Laravel's Http
        // facade is global, so the equivalent guarantee has to be switched on.
        if (! config('services.book_search.live_tests')) {
            Http::preventStrayRequests();
        }
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Act as a given reader.
 *
 * .NET counterpart: WebTestClient.RegisterAsync in the source's test
 * infrastructure, which registers a real account and keeps the auth cookie.
 * Version 1 has no authentication, so this seeds the session key CurrentUser
 * reads. When real auth lands, this becomes actingAs($user) and every test that
 * uses it keeps working unchanged, which is the point of routing all of them
 * through one helper.
 */
function actingAsReader(User $user): TestCase
{
    return test()->actingAs($user);
}
