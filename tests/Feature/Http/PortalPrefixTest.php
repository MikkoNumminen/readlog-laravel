<?php

use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;

/*
| The portal headers make every generated URL live under the portfolio's path.
| These pin the three things that matter: links carry the prefix, redirects
| carry the prefix, and garbage in the headers changes nothing.
*/

const PORTAL = ['X-Portal-Host' => 'mikkonumminen.dev', 'X-Portal-Prefix' => '/readlog-laravel'];

it('prefixes links, assets and form targets when the portal headers are present', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create(['title' => 'Portal Book']);
    ReadEntry::factory()->for($user)->for($book)->create();

    $html = actingAsReader($user)->withHeaders(PORTAL)->get('/library')->assertOk()->getContent();

    expect($html)
        ->toContain('https://mikkonumminen.dev/readlog-laravel/css/site.css')
        ->toContain('https://mikkonumminen.dev/readlog-laravel/library?view=list')
        ->toContain('https://mikkonumminen.dev/readlog-laravel/log')
        ->not->toContain('http://localhost:8000/library');
});

it('prefixes redirects', function () {
    $user = User::factory()->create();
    $book = Book::factory()->create();
    $entry = ReadEntry::factory()->for($user)->for($book)->create();

    actingAsReader($user)->withHeaders(PORTAL)
        ->delete("/library/{$entry->id}")
        ->assertRedirect('https://mikkonumminen.dev/readlog-laravel/library');
});

it('generates normal URLs without the headers, and ignores malformed ones', function (array $headers) {
    $user = User::factory()->create();

    $html = actingAsReader($user)->withHeaders($headers)->get('/library')->assertOk()->getContent();

    expect($html)->toContain('http://localhost:8000/library')
        ->not->toContain('mikkonumminen.dev');
})->with([
    'no headers' => [[]],
    'prefix without host' => [['X-Portal-Prefix' => '/readlog-laravel']],
    'host without prefix' => [['X-Portal-Host' => 'mikkonumminen.dev']],
    'traversal in prefix' => [['X-Portal-Host' => 'mikkonumminen.dev', 'X-Portal-Prefix' => '/../etc']],
    'nested prefix' => [['X-Portal-Host' => 'mikkonumminen.dev', 'X-Portal-Prefix' => '/a/b']],
    'url as host' => [['X-Portal-Host' => 'https://evil.test', 'X-Portal-Prefix' => '/readlog-laravel']],
    'host with port' => [['X-Portal-Host' => 'evil.test:8443', 'X-Portal-Prefix' => '/readlog-laravel']],
]);
