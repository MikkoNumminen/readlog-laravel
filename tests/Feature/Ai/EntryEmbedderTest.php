<?php

use App\Enums\Format;
use App\Exceptions\OllamaUnavailableException;
use App\Models\ReadEntry;
use App\Models\ReadEntryEmbedding;
use App\Models\User;
use App\Services\Ai\EntryEmbedder;
use App\Services\ReadLogService;
use App\Support\LogBookData;
use App\Support\UpdateReadEntryData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
| Embeddings ride along with writes and are never allowed to break one. These
| pin the text an entry becomes, the skip-when-unchanged rule, and every way
| Ollama can be absent while a book still gets logged.
*/

const OLLAMA_HOST = 'ollama.test:11434';

beforeEach(function () {
    config()->set('services.ollama.enabled', true);
    config()->set('services.ollama.url', 'http://'.OLLAMA_HOST);
});

function embedder(): EntryEmbedder
{
    return app(EntryEmbedder::class);
}

/** Fakes /api/embed with a vector derived from each input's length, so different texts get different vectors. */
function fakeEmbed(): void
{
    Http::fake([
        OLLAMA_HOST.'/api/tags' => Http::response(['models' => []]),
        OLLAMA_HOST.'/api/embed' => function ($request) {
            $inputs = $request['input'];

            return Http::response(['embeddings' => array_map(fn (string $t) => [strlen($t), 1.0], $inputs)]);
        },
    ]);
}

function logged(User $user, string $title, string $finishedAt = '2024-05-01', ?int $rating = 5, Format $format = Format::Audiobook): ReadEntry
{
    app(ReadLogService::class)->logBook($user->id, new LogBookData(
        openLibraryId: 'ol:'.md5($title),
        title: $title,
        author: 'Ursula K. Le Guin',
        coverUrl: null,
        pageCount: 200,
        firstPublishYear: 1974,
        format: $format,
        finishedAt: $finishedAt,
        rating: $rating,
    ));

    return ReadEntry::query()->whereHas('book', fn ($q) => $q->where('title', $title))->firstOrFail();
}

it('describes an entry as one plain sentence group the model can embed', function () {
    config()->set('services.ollama.enabled', false); // no embedding on write for this one
    $entry = logged(User::factory()->create(), 'The Dispossessed');

    expect(embedder()->textFor($entry))->toBe(
        'The Dispossessed by Ursula K. Le Guin. Read as audiobook. Rated 5 out of 5. Finished on May 1, 2024. First published in 1974. 200 pages.'
    );
});

it('embeds on write when Ollama is up, and skips unchanged entries after that', function () {
    fakeEmbed();
    $entry = logged(User::factory()->create(), 'The Dispossessed');

    $stored = ReadEntryEmbedding::query()->where('read_entry_id', $entry->id)->firstOrFail();
    expect($stored->model)->toBe('nomic-embed-text')
        ->and($stored->dimensions)->toBe(2)
        // What was embedded is the document prefix plus the entry text.
        ->and($stored->vector)->toBe([(float) strlen('search_document: '.embedder()->textFor($entry)), 1.0]);

    Http::assertSentCount(2); // one probe, one embed
    expect(embedder()->embed($entry))->toBeTrue();
    Http::assertSentCount(2); // content hash matched: no second embed call
});

it('re-embeds an entry whose text changed', function () {
    fakeEmbed();
    $user = User::factory()->create();
    $entry = logged($user, 'The Dispossessed');
    $before = $entry->embedding->content_hash;

    app(ReadLogService::class)->updateReadEntry($user->id, $entry->id, new UpdateReadEntryData(Format::Ebook, '2024-05-01', 3));

    $after = ReadEntryEmbedding::query()->where('read_entry_id', $entry->id)->firstOrFail();
    expect($after->content_hash)->not->toBe($before)
        ->and(embedder()->textFor($entry->fresh()))->toContain('Read as e-book', 'Rated 3');
});

it('re-embeds when the embedding model changes', function () {
    fakeEmbed();
    $entry = logged(User::factory()->create(), 'The Dispossessed');

    config()->set('services.ollama.embed_model', 'other-model');
    embedder()->embed($entry->fresh());

    expect($entry->embedding()->firstOrFail()->model)->toBe('other-model');
});

it('saves the entry even when Ollama dies mid-write, and leaves the gap to fill later', function () {
    Http::fake([
        OLLAMA_HOST.'/api/tags' => Http::response(['models' => []]),
        OLLAMA_HOST.'/api/embed' => fn () => throw new ConnectionException('gone'),
    ]);

    $entry = logged(User::factory()->create(), 'The Dispossessed');

    expect($entry->exists)->toBeTrue()
        ->and(ReadEntryEmbedding::query()->count())->toBe(0)
        ->and(embedder()->embed($entry))->toBeFalse();

    // The batch API is the one that tells the caller.
    expect(fn () => embedder()->embedMany(new Collection([$entry])))->toThrow(OllamaUnavailableException::class);
});

it('probes once when Ollama is unreachable, then stops trying', function () {
    $probes = 0;
    Http::fake([OLLAMA_HOST.'/api/tags' => function () use (&$probes) {
        $probes++;
        throw new ConnectionException('refused');
    }]);
    $user = User::factory()->create();

    logged($user, 'One');
    logged($user, 'Two');

    expect($probes)->toBe(1) // cached as "down"; no embed attempted for either write
        ->and(ReadEntryEmbedding::query()->count())->toBe(0);
});

it('deletes the embedding with the entry', function () {
    fakeEmbed();
    $user = User::factory()->create();
    $entry = logged($user, 'The Dispossessed');
    expect(ReadEntryEmbedding::query()->count())->toBe(1);

    app(ReadLogService::class)->deleteReadEntry($user->id, $entry->id);

    expect(ReadEntryEmbedding::query()->count())->toBe(0);
});

it('backfills missing embeddings in one request per chunk with readlog:embed', function () {
    config()->set('services.ollama.enabled', false);
    $user = User::factory()->create();
    logged($user, 'One');
    logged($user, 'Two');
    logged($user, 'Three');
    expect(ReadEntryEmbedding::query()->count())->toBe(0);

    config()->set('services.ollama.enabled', true);
    fakeEmbed();

    $this->artisan('readlog:embed', ['--chunk' => 2])
        ->expectsOutputToContain('3 entries seen, 3 embedded')
        ->assertSuccessful();

    expect(ReadEntryEmbedding::query()->count())->toBe(3);
    Http::assertSentCount(3); // probe + two chunks

    $this->artisan('readlog:embed')->expectsOutputToContain('0 embedded')->assertSuccessful();

    $this->artisan('readlog:embed', ['--fresh' => true])->expectsOutputToContain('Dropped 3')->assertSuccessful();
    expect(ReadEntryEmbedding::query()->count())->toBe(3);

    // Ollama dying half way through a backfill is reported as a failure, not as
    // "done". Last in this test: Http::fake invokes every matching stub, so a
    // throwing one cannot be un-registered by a later fake().
    Http::fake([OLLAMA_HOST.'/api/embed' => fn () => throw new ConnectionException('gone')]);
    config()->set('services.ollama.embed_model', 'model-b');
    $this->artisan('readlog:embed')->expectsOutputToContain('Stopped after 0 embedded')->assertFailed();
});

it('readlog:embed fails clearly when Ollama is not reachable', function () {
    Http::fake([OLLAMA_HOST.'/api/tags' => fn () => throw new ConnectionException('refused')]);

    $this->artisan('readlog:embed')
        ->expectsOutputToContain('not reachable at http://'.OLLAMA_HOST)
        ->assertFailed();
});

it('readlog:embed is a no-op when AI search is disabled', function () {
    config()->set('services.ollama.enabled', false);
    Http::fake();

    $this->artisan('readlog:embed')->expectsOutputToContain('disabled')->assertSuccessful();
    Http::assertNothingSent();
});

it('cosine similarity is 1 for parallel, 0 for orthogonal, and 0 for junk', function () {
    expect(EntryEmbedder::cosine([1.0, 2.0], [2.0, 4.0]))->toEqualWithDelta(1.0, 1e-9)
        ->and(EntryEmbedder::cosine([1.0, 0.0], [0.0, 1.0]))->toBe(0.0)
        ->and(EntryEmbedder::cosine([1.0, 0.0], [0.0]))->toBe(0.0)
        ->and(EntryEmbedder::cosine([0.0, 0.0], [1.0, 1.0]))->toBe(0.0);
});

it('artisan list shows the command', function () {
    Artisan::call('list');
    expect(Artisan::output())->toContain('readlog:embed');
});
