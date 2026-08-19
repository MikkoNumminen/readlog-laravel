<?php

namespace App\Services\Ai;

use App\Exceptions\OllamaUnavailableException;
use App\Support\Redact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that talks to Ollama, over plain HTTP on localhost.
 *
 * .NET counterpart: none in readlog-dotnet. The nearest relative in this codebase
 * is the two book-provider clients: a typed wrapper over Http with a base URL, a
 * timeout, and a single way of failing.
 *
 * Everything the app does with Ollama is optional, and this class is where that
 * promise is kept. isAvailable() is a cheap probe with a short timeout, cached,
 * so a page that might use AI decides in milliseconds whether it can, and never
 * waits on a dead socket. Every other method throws OllamaUnavailableException
 * for every kind of failure, so callers make one decision: degrade.
 */
class OllamaClient
{
    private const PROBE_CACHE_KEY = 'ollama:available';

    public function enabled(): bool
    {
        return (bool) config('services.ollama.enabled');
    }

    public function baseUrl(): string
    {
        return (string) config('services.ollama.url');
    }

    public function embedModel(): string
    {
        return (string) config('services.ollama.embed_model');
    }

    public function chatModel(): string
    {
        return (string) config('services.ollama.chat_model');
    }

    /**
     * Is Ollama reachable right now? Cached for a minute either way, so a page
     * request pays for the probe once, not on every render.
     */
    public function isAvailable(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return (bool) Cache::remember(
            self::PROBE_CACHE_KEY,
            (int) config('services.ollama.probe_cache_seconds'),
            function (): bool {
                try {
                    return Http::timeout((int) config('services.ollama.probe_timeout'))
                        ->get($this->baseUrl().'/api/tags')
                        ->ok();
                } catch (Throwable $e) {
                    Log::info('Ollama not reachable; AI search degrades to title matching.', [
                        'url' => $this->baseUrl(),
                        'reason' => Redact::apiKey($e->getMessage()),
                    ]);

                    return false;
                }
            },
        );
    }

    /** Forget the cached probe, so the next isAvailable() asks again. */
    public function forgetAvailability(): void
    {
        Cache::forget(self::PROBE_CACHE_KEY);
    }

    /**
     * Embeds one or more texts with the configured embedding model.
     *
     * @param  list<string>  $texts
     * @param  int|null  $timeout  seconds; null means the configured embed_timeout
     * @return list<list<float>> one vector per input text, in order
     *
     * @throws OllamaUnavailableException
     */
    public function embed(array $texts, ?int $timeout = null): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->post('/api/embed', [
            'model' => $this->embedModel(),
            'input' => $texts,
        ], $timeout ?? (int) config('services.ollama.embed_timeout'));

        $vectors = $response->json('embeddings');

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            throw new OllamaUnavailableException('Ollama returned an unexpected embedding response.');
        }

        return array_map(fn ($vector) => array_map(floatval(...), is_array($vector) ? array_values($vector) : []), $vectors);
    }

    /**
     * Generates a completion. With $json true, Ollama is asked to constrain the
     * output to valid JSON, which is what the answer step relies on.
     *
     * @throws OllamaUnavailableException
     */
    public function generate(string $prompt, bool $json = false): string
    {
        $body = [
            'model' => $this->chatModel(),
            'prompt' => $prompt,
            'stream' => false,
            'options' => ['temperature' => 0],
        ];

        if ($json) {
            $body['format'] = 'json';
        }

        $response = $this->post('/api/generate', $body, (int) config('services.ollama.generate_timeout'));

        $text = $response->json('response');

        if (! is_string($text)) {
            throw new OllamaUnavailableException('Ollama returned an unexpected generate response.');
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body, int $timeout): Response
    {
        if (! $this->enabled()) {
            throw new OllamaUnavailableException('AI search is disabled (AI_SEARCH_ENABLED=false).');
        }

        try {
            $response = Http::timeout($timeout)->acceptJson()->post($this->baseUrl().$path, $body);
        } catch (ConnectionException $e) {
            // The exception message is what the page shows, so it is one plain
            // sentence; the cURL detail goes to the log. A timeout is called out
            // separately because on a shared GPU it usually means "a model is
            // still loading, ask again", which is worth telling the reader.
            $detail = Redact::apiKey($e->getMessage());
            Log::info('Ollama request failed.', ['path' => $path, 'timeout' => $timeout, 'detail' => $detail]);
            $timedOut = str_contains($detail, 'timed out') || str_contains($detail, 'cURL error 28');
            if (! $timedOut) {
                $this->forgetAvailability();
            }

            throw new OllamaUnavailableException($timedOut
                ? "Ollama at {$this->baseUrl()} did not answer within {$timeout} s; a model may still be loading, try again."
                : "Ollama is not reachable at {$this->baseUrl()}.", 0, $e);
        }

        if ($response->failed()) {
            Log::info('Ollama answered with an error.', ['path' => $path, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            throw new OllamaUnavailableException("Ollama at {$this->baseUrl()} answered {$response->status()} for {$path}.");
        }

        return $response;
    }
}
