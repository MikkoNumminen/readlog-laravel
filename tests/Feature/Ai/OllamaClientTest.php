<?php

use App\Exceptions\OllamaUnavailableException;
use App\Services\Ai\OllamaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| The Ollama client is the seam every AI feature degrades through, so these pin
| the degradation, not the happy path: disabled, unreachable, wrong shape, all
| collapse into one exception or one false, and the probe result is cached.
*/

const OLLAMA = 'ollama.test:11434';

beforeEach(function () {
    config()->set('services.ollama.enabled', true);
    config()->set('services.ollama.url', 'http://'.OLLAMA);
});

function ollama(): OllamaClient
{
    return app(OllamaClient::class);
}

it('reports availability from /api/tags and caches the answer', function () {
    Http::fake([OLLAMA.'/api/tags' => Http::response(['models' => []])]);

    expect(ollama()->isAvailable())->toBeTrue()
        ->and(ollama()->isAvailable())->toBeTrue();

    Http::assertSentCount(1);
});

it('is unavailable when the probe fails, without throwing', function () {
    Http::fake([OLLAMA.'/api/tags' => fn () => throw new ConnectionException('refused')]);

    expect(ollama()->isAvailable())->toBeFalse();
});

it('is unavailable when AI search is switched off, and never touches the network', function () {
    config()->set('services.ollama.enabled', false);
    Http::fake();

    expect(ollama()->isAvailable())->toBeFalse();
    Http::assertNothingSent();

    expect(fn () => ollama()->embed(['x']))->toThrow(OllamaUnavailableException::class, 'disabled');
});

it('embeds a batch and returns one float vector per input, in order', function () {
    Http::fake([OLLAMA.'/api/embed' => Http::response(['embeddings' => [[1, 0], ['0.5', 0.5]]])]);

    $vectors = ollama()->embed(['first', 'second']);

    expect($vectors)->toBe([[1.0, 0.0], [0.5, 0.5]]);
    Http::assertSent(fn ($request) => $request['model'] === config('services.ollama.embed_model')
        && $request['input'] === ['first', 'second']);
});

it('treats a wrong-shaped embedding response as unavailable', function () {
    Http::fake([OLLAMA.'/api/embed' => Http::response(['embeddings' => [[1, 0]]])]);

    expect(fn () => ollama()->embed(['first', 'second']))->toThrow(OllamaUnavailableException::class);
});

it('turns a connection failure into the one exception and forgets the cached probe', function () {
    Http::fake([
        OLLAMA.'/api/tags' => Http::response(['models' => []]),
        OLLAMA.'/api/embed' => fn () => throw new ConnectionException('reset by peer'),
    ]);

    expect(ollama()->isAvailable())->toBeTrue()
        ->and(Cache::has('ollama:available'))->toBeTrue();

    expect(fn () => ollama()->embed(['x']))->toThrow(OllamaUnavailableException::class, 'not reachable at http://'.OLLAMA);
    expect(Cache::has('ollama:available'))->toBeFalse();
});

it('says "did not answer within" for a timeout, and keeps the probe cached because Ollama is up, just busy', function () {
    Http::fake([
        OLLAMA.'/api/tags' => Http::response(['models' => []]),
        OLLAMA.'/api/generate' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 90001 milliseconds'),
    ]);
    config()->set('services.ollama.generate_timeout', 90);

    expect(ollama()->isAvailable())->toBeTrue();
    expect(fn () => ollama()->generate('hi'))->toThrow(OllamaUnavailableException::class, 'did not answer within 90 s');
    expect(Cache::has('ollama:available'))->toBeTrue();
});

it('turns a non-2xx answer into the one exception', function () {
    Http::fake([OLLAMA.'/api/generate' => Http::response('model not found', 404)]);

    expect(fn () => ollama()->generate('hi'))->toThrow(OllamaUnavailableException::class, 'answered 404 for /api/generate');
});

it('generates with temperature 0 and asks for JSON when told to', function () {
    Http::fake([OLLAMA.'/api/generate' => Http::response(['response' => '{"answer":"yes"}'])]);

    expect(ollama()->generate('question', json: true))->toBe('{"answer":"yes"}');

    Http::assertSent(fn ($request) => $request['format'] === 'json'
        && $request['stream'] === false
        && $request['options']['temperature'] === 0
        && $request['model'] === config('services.ollama.chat_model'));
});
