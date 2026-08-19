<?php

namespace App\Console\Commands;

use App\Models\ReadEntry;
use App\Models\ReadEntryEmbedding;
use App\Services\Ai\EntryEmbedder;
use App\Services\Ai\OllamaClient;
use Illuminate\Console\Command;

/**
 * Backfills embeddings for every reading entry that lacks a current one.
 *
 *     php artisan readlog:embed            # embed what is missing or stale
 *     php artisan readlog:embed --fresh    # drop everything and embed again
 *
 * Writes embed as they happen when Ollama is up, and searches fill small gaps
 * as they go; this is for the two cases those do not cover: a database that
 * existed before Ollama did, and a change of embedding model.
 */
class EmbedEntries extends Command
{
    protected $signature = 'readlog:embed
        {--fresh : Discard every stored embedding first}
        {--chunk=50 : Entries per request to Ollama}';

    protected $description = 'Compute embeddings for reading entries that lack a current one (needs Ollama)';

    public function handle(OllamaClient $ollama, EntryEmbedder $embedder): int
    {
        if (! $ollama->enabled()) {
            $this->components->warn('AI search is disabled (AI_SEARCH_ENABLED=false); nothing to do.');

            return self::SUCCESS;
        }

        $ollama->forgetAvailability();
        if (! $ollama->isAvailable()) {
            $this->components->error("Ollama is not reachable at {$ollama->baseUrl()}. Start it, or set OLLAMA_URL.");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $dropped = ReadEntryEmbedding::query()->delete();
            $this->components->info("Dropped {$dropped} stored embedding(s).");
        }

        $embedded = 0;
        $seen = 0;
        ReadEntry::query()->with(['book', 'embedding'])->orderBy('id')
            ->chunkById(max(1, (int) $this->option('chunk')), function ($entries) use (&$embedded, &$seen, $embedder) {
                $seen += $entries->count();
                $embedded += $embedder->embedMany($entries);
            });

        $this->components->info("{$seen} entries seen, {$embedded} embedded with {$ollama->embedModel()}; the rest were already current.");

        return self::SUCCESS;
    }
}
