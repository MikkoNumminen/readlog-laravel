<?php

namespace App\Services\Ai;

use App\Exceptions\OllamaUnavailableException;
use App\Models\ReadEntry;
use App\Support\AskResult;
use App\Support\LibraryEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * "Ask your library": a question in plain words, answered from the reader's own
 * entries by a local model, and nothing else.
 *
 * .NET counterpart: none. This is the one feature added beyond the port, and
 * it is built so that removing Ollama removes only this.
 *
 * Three layers, in order, each allowed to fail downward:
 *
 *   1. LibraryQuestion pulls the exact constraints out of the question (format,
 *      rating, year) and they become WHERE clauses. Deterministic.
 *   2. The remaining candidates are ranked by cosine similarity between the
 *      question's embedding and each entry's stored one. Missing embeddings are
 *      filled on the spot, up to a bound, so a library that predates Ollama
 *      still works without a manual backfill.
 *   3. The top few are shown to the chat model with the question, and it is
 *      asked for a short answer plus the ids it relied on, as JSON. Ids that
 *      were not in the list are dropped, so it can only ever cite what it saw.
 *
 * If layer 3 fails (timeout, malformed JSON) the reader still gets layer 2's
 * ranked list with a note. If Ollama is unreachable before layer 2, the result
 * is "unavailable" and the page falls back to the plain title search.
 */
class LibraryAsk
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly EntryEmbedder $embedder,
    ) {}

    public function ask(int $userId, string $question): AskResult
    {
        $question = trim($question);

        if (! $this->ollama->enabled()) {
            return AskResult::unavailable($question, 'AI search is disabled (AI_SEARCH_ENABLED=false).');
        }

        if (! $this->ollama->isAvailable()) {
            return AskResult::unavailable($question, "Ollama is not reachable at {$this->ollama->baseUrl()}.");
        }

        $parsed = LibraryQuestion::parse($question, CarbonImmutable::now());

        // Layer 1: exact filters. If they leave nothing, drop them rather than
        // answer "nothing" to "audiobooks about dragons" when the dragons were
        // paperbacks; the model is told the filters were relaxed.
        $candidates = $this->candidates($userId, $parsed);
        $applied = $parsed->applied;
        $relaxed = false;
        if ($candidates->isEmpty() && $parsed->hasFilters()) {
            $candidates = $this->candidates($userId, null);
            $applied = [];
            $relaxed = true;
        }

        if ($candidates->isEmpty()) {
            return AskResult::matchesOnly($question, collect(), $applied, 'Your library is empty; log a book first.');
        }

        // Layer 2: rank by embedding.
        try {
            $this->embedder->embedMany($candidates->take((int) config('services.ollama.ask_backfill_limit')));
            [$queryVector] = $this->ollama->embed([config('services.ollama.embed_query_prefix').$question]);
        } catch (OllamaUnavailableException $e) {
            Log::info('Ask your library: embedding failed, falling back to title matching.', ['reason' => $e->getMessage()]);

            return AskResult::unavailable($question, $e->getMessage());
        }

        // Ties keep the candidates' order, which is most recent first (usort is
        // stable since PHP 8), so an uninformative question lists recent reads.
        $ranked = $candidates
            ->filter(fn (ReadEntry $entry) => $entry->embedding !== null)
            ->sortByDesc(fn (ReadEntry $entry) => EntryEmbedder::cosine($queryVector, $entry->embedding->vector))
            ->take((int) config('services.ollama.ask_candidates'))
            ->values();

        if ($ranked->isEmpty()) {
            // Every candidate is still unembedded (a huge library on a first
            // ask, or embed() failing silently upstream). Not an error; say so.
            return AskResult::matchesOnly($question, collect(), $applied, 'No entries are indexed for AI search yet. Run `php artisan readlog:embed`, then ask again.');
        }

        $closest = $ranked->map(LibraryEntry::fromModel(...))->values();

        // Layer 3: phrase an answer over what layer 2 found.
        try {
            $raw = $this->ollama->generate($this->prompt($question, $ranked, $applied, $relaxed), json: true);
        } catch (OllamaUnavailableException $e) {
            Log::info('Ask your library: the chat model did not answer; showing ranked matches.', ['reason' => $e->getMessage()]);

            return AskResult::matchesOnly($question, $closest, $applied, 'The model did not answer in time; these are the closest entries.');
        }

        $parsedAnswer = $this->parseAnswer($raw, $ranked->pluck('id')->all());
        if ($parsedAnswer === null) {
            Log::info('Ask your library: unusable model output.', ['head' => mb_substr($raw, 0, 200)]);

            return AskResult::matchesOnly($question, $closest, $applied, 'The model gave an answer that could not be read; these are the closest entries.');
        }

        [$answer, $citedIds] = $parsedAnswer;
        $cited = $closest->filter(fn (LibraryEntry $e) => in_array($e->id, $citedIds, true))->values();

        return AskResult::answered($question, $answer, $cited, $closest, $applied);
    }

    /**
     * @return EloquentCollection<int, ReadEntry>
     */
    private function candidates(int $userId, ?LibraryQuestion $filters): EloquentCollection
    {
        $query = ReadEntry::query()->with(['book', 'embedding'])->where('user_id', $userId);
        if ($filters !== null) {
            $filters->apply($query);
        }

        return $query->orderByDesc('finished_at')->orderByDesc('id')->get();
    }

    /**
     * @param  Collection<int, ReadEntry>  $entries
     * @param  list<string>  $applied
     */
    private function prompt(string $question, Collection $entries, array $applied, bool $relaxed): string
    {
        $lines = $entries->map(fn (ReadEntry $e) => $e->id.': '.$this->embedder->textFor($e))->implode("\n");
        $today = CarbonImmutable::now()->format('F j, Y');
        $scope = $applied === []
            ? 'These are the closest entries in the whole library.'
            : 'These are the closest entries among those that are '.implode(', ', $applied).'.';
        if ($relaxed) {
            $scope = 'No entry matched the exact constraints in the question, so these are the closest entries in the whole library; say that in the answer.';
        }

        return <<<PROMPT
        You answer questions about one reader's personal reading log. Today is {$today}.
        Use only the entries listed below; they are everything you know. Do not invent books, dates or ratings.
        When the question asks what or which entries fit, name every entry that fits, not just the first.
        Refer to entries by title and author in the answer, never by id.
        If the entries do not answer the question, say so in one sentence.
        {$scope}

        Question: {$question}

        Entries (id: description):
        {$lines}

        Reply with JSON only, exactly this shape, no other text:
        {"answer": "one to three plain sentences", "cited": [the ids of the entries the answer is about, as numbers]}
        PROMPT;
    }

    /**
     * @param  list<int>  $allowedIds
     * @return array{0: string, 1: list<int>}|null
     */
    private function parseAnswer(string $raw, array $allowedIds): ?array
    {
        $data = json_decode(trim($raw), true);
        if (! is_array($data) || ! isset($data['answer']) || ! is_string($data['answer']) || trim($data['answer']) === '') {
            return null;
        }

        $cited = [];
        foreach (is_array($data['cited'] ?? null) ? $data['cited'] : [] as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && in_array((int) $id, $allowedIds, true)) {
                $cited[] = (int) $id;
            }
        }

        return [trim($data['answer']), array_values(array_unique($cited))];
    }
}
