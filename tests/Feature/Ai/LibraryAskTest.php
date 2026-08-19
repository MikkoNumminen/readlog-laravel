<?php

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\ReadEntryEmbedding;
use App\Models\User;
use App\Services\Ai\LibraryAsk;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| "Ask your library", end to end against a faked Ollama. The fake embedder is
| a keyword lookup, so the ranking is decided by the test and the assertions
| are about what the service does with a ranking: which entries reach the
| model, how the model's ids are checked, and every way it degrades.
*/

const ASK_HOST = 'ollama.test:11434';

beforeEach(function () {
    config()->set('services.ollama.enabled', true);
    config()->set('services.ollama.url', 'http://'.ASK_HOST);
});

function asker(): LibraryAsk
{
    return app(LibraryAsk::class);
}

/** Three axes: desert/Dune, dragon/Hobbit, everything else. */
function keywordVector(string $text): array
{
    $t = mb_strtolower($text);
    if (str_contains($t, 'dune') || str_contains($t, 'desert')) {
        return [1.0, 0.0, 0.0];
    }
    if (str_contains($t, 'hobbit') || str_contains($t, 'dragon')) {
        return [0.0, 1.0, 0.0];
    }

    return [0.0, 0.0, 1.0];
}

/**
 * @param  array<string, mixed>|callable|null  $generate  the JSON the model "returns", or a callable making the response
 */
function fakeOllama(array|callable|null $generate = ['answer' => 'You read Dune.', 'cited' => []]): void
{
    Http::fake([
        ASK_HOST.'/api/tags' => Http::response(['models' => []]),
        ASK_HOST.'/api/embed' => fn ($request) => Http::response(['embeddings' => array_map(keywordVector(...), $request['input'])]),
        ASK_HOST.'/api/generate' => is_callable($generate)
            ? $generate
            : Http::response(['response' => json_encode($generate)]),
    ]);
}

function shelf(User $user): array
{
    $dune = Book::factory()->create(['title' => 'Dune', 'author' => 'Frank Herbert']);
    $hobbit = Book::factory()->create(['title' => 'The Hobbit', 'author' => 'J. R. R. Tolkien']);
    $other = Book::factory()->create(['title' => 'Cooking for One', 'author' => 'Nobody']);

    return [
        'dune' => ReadEntry::factory()->for($user)->for($dune)->create(['format' => Format::Audiobook, 'rating' => 5, 'finished_at' => '2025-03-01']),
        'hobbit' => ReadEntry::factory()->for($user)->for($hobbit)->create(['format' => Format::Book, 'rating' => 4, 'finished_at' => '2024-06-01']),
        'other' => ReadEntry::factory()->for($user)->for($other)->create(['format' => Format::Ebook, 'rating' => null, 'finished_at' => '2026-01-01']),
    ];
}

it('answers from the ranked entries and keeps only cited ids it actually showed', function () {
    $user = User::factory()->create();
    $e = shelf($user);
    fakeOllama(fn ($request) => Http::response(['response' => json_encode([
        'answer' => 'The desert one is Dune.',
        'cited' => [$e['dune']->id, 999999, 'x'],
    ])]));

    $result = asker()->ask($user->id, 'the one about a desert planet');

    expect($result->unavailable)->toBeFalse()
        ->and($result->answer)->toBe('The desert one is Dune.')
        ->and($result->cited->pluck('id')->all())->toBe([$e['dune']->id])
        ->and($result->closest->first()->book->title)->toBe('Dune') // ranked first by the embedding
        ->and($result->applied)->toBe([]);

    // What the model saw but did not cite stays visible, separately; ties in the
    // ranking (both score 0 here) keep most-recent-first order.
    expect($result->others()->pluck('book.title')->all())->toBe(['Cooking for One', 'The Hobbit'])
        ->and($result->shown()->count())->toBe(1);

    // Every entry got embedded on the spot (none had a vector), then the question.
    expect(ReadEntryEmbedding::query()->count())->toBe(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/embed')
        && $request['input'] === ['search_query: the one about a desert planet']);
});

it('sends the model only entries that pass the exact filters, and tells the page what was applied', function () {
    $user = User::factory()->create();
    $e = shelf($user);
    $prompts = [];
    fakeOllama(function ($request) use (&$prompts) {
        $prompts[] = $request['prompt'];

        return Http::response(['response' => json_encode(['answer' => 'One audiobook.', 'cited' => []])]);
    });

    $result = asker()->ask($user->id, 'audiobooks I rated 5');

    expect($result->applied)->toBe(['audiobooks', 'rated 5'])
        ->and($result->closest->pluck('id')->all())->toBe([$e['dune']->id])
        ->and($prompts)->toHaveCount(1)
        ->and($prompts[0])->toContain('Dune by Frank Herbert', 'audiobooks, rated 5')
        ->not->toContain('The Hobbit');
});

it('applies "since" as a lower bound on the finished year', function () {
    $user = User::factory()->create();
    $e = shelf($user);
    fakeOllama();

    $result = asker()->ask($user->id, 'what have I read since 2025');

    expect($result->applied)->toBe(['finished since 2025'])
        ->and($result->closest->pluck('id')->sort()->values()->all())->toBe(collect([$e['dune']->id, $e['other']->id])->sort()->values()->all());
});

it('relaxes filters that match nothing rather than answering from nothing', function () {
    $user = User::factory()->create();
    shelf($user);
    $prompts = [];
    fakeOllama(function ($request) use (&$prompts) {
        $prompts[] = $request['prompt'];

        return Http::response(['response' => json_encode(['answer' => 'No e-book about a desert; Dune is an audiobook.', 'cited' => []])]);
    });

    $result = asker()->ask($user->id, 'e-books about a desert planet rated 5');

    expect($result->applied)->toBe([]) // dropped
        ->and($result->closest->first()->book->title)->toBe('Dune')
        ->and($prompts[0])->toContain('No entry matched the exact constraints');
});

it('is unavailable when Ollama is disabled or unreachable, without touching the database', function () {
    $user = User::factory()->create();
    shelf($user);

    config()->set('services.ollama.enabled', false);
    Http::fake();
    $off = asker()->ask($user->id, 'anything');
    expect($off->unavailable)->toBeTrue()->and($off->reason)->toContain('disabled');
    Http::assertNothingSent();

    config()->set('services.ollama.enabled', true);
    Http::fake([ASK_HOST.'/api/tags' => fn () => throw new ConnectionException('refused')]);
    $down = asker()->ask($user->id, 'anything');
    expect($down->unavailable)->toBeTrue()->and($down->reason)->toContain('not reachable at http://'.ASK_HOST);
    expect(ReadEntryEmbedding::query()->count())->toBe(0);
});

it('is unavailable when embedding fails after a good probe', function () {
    $user = User::factory()->create();
    shelf($user);
    Http::fake([
        ASK_HOST.'/api/tags' => Http::response(['models' => []]),
        ASK_HOST.'/api/embed' => Http::response('boom', 500),
    ]);

    $result = asker()->ask($user->id, 'anything');

    expect($result->unavailable)->toBeTrue()->and($result->reason)->toContain('500');
});

it('shows the ranked entries with a notice when the model does not answer or talks nonsense', function () {
    $user = User::factory()->create();
    shelf($user);

    fakeOllama(fn () => Http::response(['response' => 'Sure! Here is some prose and no JSON.']));
    $prose = asker()->ask($user->id, 'dragons');
    expect($prose->unavailable)->toBeFalse()
        ->and($prose->answer)->toBeNull()
        ->and($prose->notice)->toContain('could not be read')
        ->and($prose->shown()->first()->book->title)->toBe('The Hobbit');

    // Last: Http::fake invokes every matching stub, so a throwing one stays in force.
    fakeOllama(fn () => throw new ConnectionException('timeout'));
    $slow = asker()->ask($user->id, 'dragons');
    expect($slow->answer)->toBeNull()
        ->and($slow->notice)->toContain('did not answer in time')
        ->and($slow->shown()->first()->book->title)->toBe('The Hobbit');
});

it('treats an empty answer string as no answer', function () {
    $user = User::factory()->create();
    shelf($user);
    fakeOllama(['answer' => '   ', 'cited' => []]);

    $result = asker()->ask($user->id, 'dragons');

    expect($result->answer)->toBeNull()->and($result->notice)->toContain('could not be read');
});

it('says so for an empty library without calling the model', function () {
    $user = User::factory()->create();
    $generated = 0;
    fakeOllama(function () use (&$generated) {
        $generated++;

        return Http::response(['response' => '{}']);
    });

    $result = asker()->ask($user->id, 'anything at all');

    expect($result->unavailable)->toBeFalse()
        ->and($result->notice)->toContain('empty')
        ->and($result->shown())->toBeEmpty()
        ->and($generated)->toBe(0);
});

it('embeds at most the configured number of missing entries per ask and ranks the rest out', function () {
    config()->set('services.ollama.ask_backfill_limit', 2);
    $user = User::factory()->create();
    shelf($user);
    fakeOllama();

    $result = asker()->ask($user->id, 'anything');

    // The two most recent got embedded and ranked; the third waits for readlog:embed.
    expect(ReadEntryEmbedding::query()->count())->toBe(2)
        ->and($result->closest)->toHaveCount(2);
});

it('never shows another reader their neighbour\'s shelf', function () {
    $owner = User::factory()->create();
    shelf($owner);
    $stranger = User::factory()->create();
    fakeOllama();

    $result = asker()->ask($stranger->id, 'the one about a desert planet');

    expect($result->notice)->toContain('empty')->and($result->shown())->toBeEmpty();
});

it('answers from the command line and warms both models with --warm', function () {
    $user = User::factory()->create();
    $e = shelf($user);
    $calls = [];
    Http::fake([
        ASK_HOST.'/api/tags' => Http::response(['models' => []]),
        ASK_HOST.'/api/embed' => function ($request) use (&$calls) {
            $calls[] = 'embed';

            return Http::response(['embeddings' => array_map(keywordVector(...), $request['input'])]);
        },
        ASK_HOST.'/api/generate' => function () use (&$calls, $e) {
            $calls[] = 'generate';

            return Http::response(['response' => json_encode(['answer' => 'Dune.', 'cited' => [$e['dune']->id]])]);
        },
    ]);

    $this->artisan('readlog:ask', ['question' => 'the desert one', '--user' => $user->id])
        ->expectsOutputToContain('Dune.')
        ->expectsOutputToContain('Based on:')
        ->assertSuccessful();

    $calls = [];
    $this->artisan('readlog:ask', ['--warm' => true])->expectsOutputToContain('Warm:')->assertSuccessful();
    expect($calls)->toBe(['embed', 'generate']);

    $this->artisan('readlog:ask')->assertExitCode(2);

    config()->set('services.ollama.enabled', false);
    $this->artisan('readlog:ask', ['question' => 'x'])->expectsOutputToContain('disabled')->assertFailed();
});
