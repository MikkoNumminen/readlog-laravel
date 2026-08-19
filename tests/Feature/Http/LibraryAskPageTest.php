<?php

use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| The "ask your library" box on the library page: present only when AI search
| is enabled, answers when Ollama is there, and turns into the title search with
| a notice when it is not. The service itself is covered in tests/Feature/Ai.
*/

const PAGE_OLLAMA = 'ollama.test:11434';

beforeEach(function () {
    config()->set('services.ollama.enabled', true);
    config()->set('services.ollama.url', 'http://'.PAGE_OLLAMA);
});

function shelfWithDune(User $user): ReadEntry
{
    $book = Book::factory()->create(['title' => 'Dune', 'author' => 'Frank Herbert']);

    return ReadEntry::factory()->for($user)->for($book)->create();
}

it('shows the ask box when AI search is enabled and hides it when it is not', function () {
    $user = User::factory()->create();

    actingAsReader($user)->get('/library')->assertOk()->assertSee('Ask your library');

    config()->set('services.ollama.enabled', false);
    actingAsReader($user)->get('/library')->assertOk()->assertDontSee('Ask your library');
});

it('answers a question and lists what the answer was based on', function () {
    $user = User::factory()->create();
    $entry = shelfWithDune($user);
    Http::fake([
        PAGE_OLLAMA.'/api/tags' => Http::response(['models' => []]),
        PAGE_OLLAMA.'/api/embed' => fn ($request) => Http::response(['embeddings' => array_fill(0, count($request['input']), [1.0, 0.0])]),
        PAGE_OLLAMA.'/api/generate' => Http::response(['response' => json_encode(['answer' => 'That is Dune, by Frank Herbert.', 'cited' => [$entry->id]])]),
    ]);

    actingAsReader($user)->get('/library?ask=the+desert+one')
        ->assertOk()
        ->assertSee('That is Dune, by Frank Herbert.')
        ->assertSee('Based on:')
        ->assertSee('Dune')
        ->assertSee('value="the desert one"', false); // the question stays in the box
});

it('falls back to title matches with a notice when Ollama is not reachable', function () {
    $user = User::factory()->create();
    shelfWithDune($user);
    Http::fake([PAGE_OLLAMA.'/api/tags' => fn () => throw new ConnectionException('refused')]);

    actingAsReader($user)->get('/library?ask=dune')
        ->assertOk()
        ->assertSee('AI search is unavailable')
        ->assertSee('not reachable at http://'.PAGE_OLLAMA)
        ->assertSee('Showing title matches instead.')
        ->assertSee('Dune');

    actingAsReader($user)->get('/library?ask=nothing+like+that')
        ->assertOk()
        ->assertSee('AI search is unavailable')
        ->assertSee('Not in your library.');
});

it('shows the closest entries with a notice when the model does not answer', function () {
    $user = User::factory()->create();
    shelfWithDune($user);
    Http::fake([
        PAGE_OLLAMA.'/api/tags' => Http::response(['models' => []]),
        PAGE_OLLAMA.'/api/embed' => fn ($request) => Http::response(['embeddings' => array_fill(0, count($request['input']), [1.0, 0.0])]),
        PAGE_OLLAMA.'/api/generate' => fn () => throw new ConnectionException('timeout'),
    ]);

    actingAsReader($user)->get('/library?ask=anything')
        ->assertOk()
        ->assertSee('did not answer in time')
        ->assertSee('Closest entries:')
        ->assertSee('Dune');
});

it('ignores an empty question and never calls Ollama for it', function () {
    $user = User::factory()->create();
    Http::fake();

    actingAsReader($user)->get('/library?ask=+')->assertOk()->assertDontSee('AI search is unavailable');
    Http::assertNothingSent();
});

it('caps the question length before it reaches Ollama', function () {
    $user = User::factory()->create();
    shelfWithDune($user);
    $seen = [];
    Http::fake([
        PAGE_OLLAMA.'/api/tags' => Http::response(['models' => []]),
        PAGE_OLLAMA.'/api/embed' => function ($request) use (&$seen) {
            $seen = array_merge($seen, $request['input']);

            return Http::response(['embeddings' => array_fill(0, count($request['input']), [1.0, 0.0])]);
        },
        PAGE_OLLAMA.'/api/generate' => Http::response(['response' => json_encode(['answer' => 'ok', 'cited' => []])]),
    ]);

    actingAsReader($user)->get('/library?ask='.str_repeat('a', 2000))->assertOk();

    $question = collect($seen)->first(fn (string $t) => str_starts_with($t, 'search_query: '));
    expect(mb_strlen($question))->toBe(mb_strlen('search_query: ') + 400);
});

it('does not carry the question through the grid and list toggle, which would ask the model again', function () {
    $user = User::factory()->create();
    shelfWithDune($user);
    Http::fake([PAGE_OLLAMA.'/api/tags' => fn () => throw new ConnectionException('refused')]);

    actingAsReader($user)->get('/library?ask=dune')
        ->assertOk()
        ->assertSee('href="http://localhost:8000/library?view=list"', false)
        ->assertDontSee('ask=dune"', false);
});

it('throttles questions per address but not plain library visits', function () {
    $user = User::factory()->create();
    shelfWithDune($user);
    Http::fake([PAGE_OLLAMA.'/api/tags' => fn () => throw new ConnectionException('refused')]);

    foreach (range(1, 10) as $i) {
        actingAsReader($user)->get('/library?ask=q'.$i)->assertOk();
    }
    actingAsReader($user)->get('/library?ask=one-too-many')->assertStatus(429);
    actingAsReader($user)->get('/library')->assertOk();
    actingAsReader($user)->get('/library?q=dune')->assertOk();
});
