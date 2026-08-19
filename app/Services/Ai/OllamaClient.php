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
     * @return list<list<float>> one vector per input text, in order
     *
     * @throws OllamaUnavailableException
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->post('/api/embed', [
            'model' => $this->embedModel(),
            'input' => $texts,
        ], (int) config('services.ollama.embed_timeout'));

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
            $this->forgetAvailability();

            throw new OllamaUnavailableException('Ollama not reachable at '.$this->baseUrl().': '.Redact::apiKey($e->getMessage()), 0, $e);
        }

        if ($response->failed()) {
            throw new OllamaUnavailableException("Ollama answered {$response->status()} for {$path}: ".mb_substr($response->body(), 0, 200));
        }

        return $response;
    }
}
