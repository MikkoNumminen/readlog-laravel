<?php

use App\Enums\Format;
use App\Models\User;
use App\Services\ReadLogService;
use App\Support\LogBookData;
use App\Support\PublicRead;
use Illuminate\Support\Facades\Cache;

/*
| Regression test for a bug the rest of the suite structurally cannot catch.
|
| phpunit.xml sets CACHE_STORE=array, and Laravel's ArrayStore keeps live PHP
| references rather than serialising, so anything at all round-trips through it.
| Every other store serialises, and Laravel 13 ships config/cache.php with
| 'serializable_classes' => false, which means unserialize() runs with
| allowed_classes: false and any object comes back as __PHP_Incomplete_Class.
|
| The first version of getRecentPublicReads cached a Collection of PublicRead
| objects. Under the array store used by the tests that was fine. Under the
| database store the app actually runs on, the first request wrote the cache and
| every request after it returned a 500. The .NET original has no equivalent
| failure mode at all: IMemoryCache hands back the same object reference it was
| given and never serialises anything.
|
| These tests therefore force a serialising store, which is the only way the
| failure is visible from a test.
*/

beforeEach(function () {
    config()->set('cache.default', 'database');
    config()->set('cache.serializable_classes', false); // the shipped default, made explicit
    Cache::clearResolvedInstances();
    Cache::flush();
});

function feedService(): ReadLogService
{
    return app(ReadLogService::class);
}

function aLoggedBook(int $userId, string $id, string $title, string $finishedAt): void
{
    feedService()->logBook($userId, new LogBookData(
        openLibraryId: $id,
        title: $title,
        author: 'Someone',
        coverUrl: null,
        pageCount: null,
        firstPublishYear: null,
        format: Format::Book,
        finishedAt: $finishedAt,
        rating: 4,
    ));
}

it('survives a second read through a serialising cache store', function () {
    $user = User::factory()->sharesPublicly()->create();
    aLoggedBook($user->id, 'ol:1', 'Dune', '2024-01-01');

    $first = feedService()->getRecentPublicReads();  // populates the cache
    $second = feedService()->getRecentPublicReads(); // reads it back

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($second->first())->toBeInstanceOf(PublicRead::class)
        ->and($second->first()->title)->toBe('Dune');
});

it('keeps every field intact across the cache round trip', function () {
    $user = User::factory()->sharesPublicly()->create();
    aLoggedBook($user->id, 'ol:1', 'Dune', '2024-01-01');

    $fresh = feedService()->getRecentPublicReads()->first();
    $cached = feedService()->getRecentPublicReads()->first();

    expect($cached->title)->toBe($fresh->title)
        ->and($cached->author)->toBe($fresh->author)
        ->and($cached->coverUrl)->toBe($fresh->coverUrl)
        ->and($cached->format)->toBe($fresh->format)
        ->and($cached->rating)->toBe($fresh->rating)
        ->and($cached->createdAt->toDateTimeString())->toBe($fresh->createdAt->toDateTimeString());
});

it('stores no PHP objects in the cache at all', function () {
    $user = User::factory()->sharesPublicly()->create();
    aLoggedBook($user->id, 'ol:1', 'Dune', '2024-01-01');

    feedService()->getRecentPublicReads();

    // The guarantee, stated directly: whatever is in the cache must survive
    // unserialize() with allowed_classes disabled.
    $cached = Cache::get('public-feed');

    expect($cached)->toBeArray()
        ->and($cached[0])->toBeArray()
        ->and(array_keys($cached[0]))
        ->toBe(['title', 'author', 'cover_url', 'format', 'created_at', 'rating']);
});
