<?php

namespace App\Console\Commands;

use App\Exceptions\OllamaUnavailableException;
use App\Models\User;
use App\Services\Ai\LibraryAsk;
use App\Services\Ai\OllamaClient;
use App\Support\LibraryEntry;
use Illuminate\Console\Command;

/**
 * "Ask your library" from the command line, and the warm-up the desktop
 * control runs after start-up.
 *
 *     php artisan readlog:ask "audiobooks I rated 5 last year"
 *     php artisan readlog:ask "the one about a desert planet" --user=2
 *     php artisan readlog:ask --warm
 *
 * --warm sends one tiny embed and one tiny generate so both models are loaded
 * before the first real question. Measured: the first question on a cold GPU
 * took 47 s and the next 3 s; that difference is what this buys.
 *
 * .NET counterpart: none. The AI search was added in this port, and
 * readlog-dotnet has no console surface at all.
 */
class AskLibrary extends Command
{
    protected $signature = 'readlog:ask
        {question? : The question, in plain words}
        {--user= : Reader id (default: the first user)}
        {--warm : Load both models with a tiny request each, then exit}';

    protected $description = 'Ask a reader\'s library a question through Ollama, or warm the models up';

    public function handle(OllamaClient $ollama, LibraryAsk $ask): int
    {
        if (! $ollama->enabled()) {
            $this->components->warn('AI search is disabled (AI_SEARCH_ENABLED=false).');

            return self::FAILURE;
        }

        if ($this->option('warm')) {
            return $this->warm($ollama);
        }

        $question = (string) $this->argument('question');
        if (trim($question) === '') {
            $this->components->error('Give a question, or --warm.');

            return self::INVALID;
        }

        $userId = $this->option('user') !== null ? (int) $this->option('user') : User::query()->orderBy('id')->value('id');
        if ($userId === null) {
            $this->components->error('No users yet; seed or log a book first.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $result = $ask->ask((int) $userId, $question);
        $seconds = round(microtime(true) - $started, 1);

        if ($result->unavailable) {
            $this->components->error("AI search unavailable: {$result->reason}");

            return self::FAILURE;
        }

        if ($result->answer !== null) {
            $this->components->info($result->answer);
        }
        if ($result->notice !== null) {
            $this->components->warn($result->notice);
        }
        if ($result->applied !== []) {
            $this->line('  Looked at: '.implode(', ', $result->applied));
        }
        if ($result->shown()->isNotEmpty()) {
            $this->line($result->cited->isNotEmpty() ? '  Based on:' : '  Closest entries:');
            $this->table(
                ['id', 'title', 'author', 'format', 'rating', 'finished'],
                $result->shown()->map(fn (LibraryEntry $e) => [
                    $e->id, $e->book->title, $e->book->author ?? '', $e->format->label(), $e->rating ?? '', $e->finishedAt->toDateString(),
                ])->all(),
            );
        }
        if ($result->others()->isNotEmpty()) {
            $this->line('  Other close entries the model saw: '.$result->others()->map(fn (LibraryEntry $e) => $e->book->title)->implode(', '));
        }
        $this->line("  ({$seconds} s)");

        return self::SUCCESS;
    }

    private function warm(OllamaClient $ollama): int
    {
        $ollama->forgetAvailability();
        if (! $ollama->isAvailable()) {
            $this->components->error("Ollama is not reachable at {$ollama->baseUrl()}.");

            return self::FAILURE;
        }

        try {
            $started = microtime(true);
            $ollama->embed(['warm up'], (int) config('services.ollama.backfill_embed_timeout'));
            $embedSeconds = round(microtime(true) - $started, 1);
            $started = microtime(true);
            $ollama->generate('Reply with the single word ok.');
            $generateSeconds = round(microtime(true) - $started, 1);
        } catch (OllamaUnavailableException $e) {
            $this->components->error("Warm-up failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Warm: {$ollama->embedModel()} in {$embedSeconds} s, {$ollama->chatModel()} in {$generateSeconds} s.");

        return self::SUCCESS;
    }
}
