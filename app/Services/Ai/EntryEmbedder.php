<?php

namespace App\Services\Ai;

use App\Exceptions\OllamaUnavailableException;
use App\Models\ReadEntry;
use App\Models\ReadEntryEmbedding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Keeps one embedding per reading entry, and knows what text an entry becomes.
 *
 * Embeddings are best effort by design. Writing an entry never fails because
 * Ollama is down: the entry is saved, the embedding is skipped, and the next
 * search or the readlog:embed command fills the gap. That is the "app never
 * depends on it" rule from TODO.md made concrete.
 */
class EntryEmbedder
{
    public function __construct(private readonly OllamaClient $ollama) {}

    /**
     * What an entry looks like to the embedding model. Title and author carry
     * most of the meaning; the format, rating and date let a question like "the
     * audiobooks I loved last summer" land on the right rows. The book's year is
     * included because readers remember "that old one" more often than a title.
     */
    public function textFor(ReadEntry $entry): string
    {
        $book = $entry->book;

        $parts = [
            $book->title.($book->author ? ' by '.$book->author : '').'.',
            'Read as '.strtolower($entry->format->label()).'.',
            $entry->rating === null ? 'Not rated.' : "Rated {$entry->rating} out of 5.",
            'Finished on '.$entry->finished_at->format('F j, Y').'.',
        ];

        if ($book->first_publish_year !== null) {
            $parts[] = "First published in {$book->first_publish_year}.";
        }

        if ($book->page_count !== null) {
            $parts[] = "{$book->page_count} pages.";
        }

        return implode(' ', $parts);
    }

    /**
     * Embeds one entry, if its stored embedding is missing or stale. Returns
     * whether an embedding now exists for it.
     */
    public function embed(ReadEntry $entry): bool
    {
        return $this->embedMany(new Collection([$entry])) === 1 || $entry->embedding()->exists();
    }

    /**
     * Embeds every entry in the collection whose embedding is missing or stale,
     * in one request to Ollama. Returns how many were (re)embedded. Never
     * throws for an unreachable Ollama; that case returns 0 and is logged once.
     *
     * @param  Collection<int, ReadEntry>  $entries
     */
    public function embedMany(Collection $entries): int
    {
        if (! $this->ollama->enabled() || $entries->isEmpty()) {
            return 0;
        }

        $entries->loadMissing(['book', 'embedding']);
        $model = $this->ollama->embedModel();

        /** @var list<array{entry: ReadEntry, text: string, hash: string}> $stale */
        $stale = [];
        foreach ($entries as $entry) {
            $text = $this->textFor($entry);
            $hash = hash('sha256', $text);
            $current = $entry->embedding;

            if ($current !== null && $current->content_hash === $hash && $current->model === $model) {
                continue;
            }

            $stale[] = ['entry' => $entry, 'text' => $text, 'hash' => $hash];
        }

        if ($stale === []) {
            return 0;
        }

        try {
            $vectors = $this->ollama->embed(array_map(fn (array $s) => $s['text'], $stale));
        } catch (OllamaUnavailableException $e) {
            Log::info('Skipping embeddings; Ollama unavailable.', ['reason' => $e->getMessage(), 'entries' => count($stale)]);

            return 0;
        }

        foreach ($stale as $i => $item) {
            $vector = $vectors[$i] ?? [];

            if ($vector === []) {
                continue;
            }

            ReadEntryEmbedding::query()->updateOrCreate(
                ['read_entry_id' => $item['entry']->id],
                [
                    'model' => $model,
                    'dimensions' => count($vector),
                    'content_hash' => $item['hash'],
                    'vector' => $vector,
                ],
            );

            $item['entry']->unsetRelation('embedding');
        }

        return count($stale);
    }

    /**
     * Cosine similarity of two vectors. Returns 0 for anything degenerate
     * (different lengths, a zero vector), which ranks it last without throwing.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
