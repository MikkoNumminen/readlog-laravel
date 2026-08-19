<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * What "ask your library" hands the page. Three shapes, one class:
 *
 * - unavailable: Ollama could not be used at all; the page shows title matches
 *   instead and says why. Nothing else is set.
 * - answered: the model gave a usable answer, and `cited` is the entries it
 *   named (validated against what it was shown).
 * - matches only: the model did not give a usable answer (or was not asked
 *   because there was nothing to ask about); `closest` still carries the ranked
 *   entries, and `notice` says what happened.
 *
 * `applied` is the deterministic layer's account of itself ("audiobooks",
 * "rated 5"), shown so the reader can see what the answer was narrowed to.
 */
final readonly class AskResult
{
    /**
     * @param  Collection<int, LibraryEntry>  $cited
     * @param  Collection<int, LibraryEntry>  $closest
     * @param  list<string>  $applied
     */
    private function __construct(
        public string $question,
        public bool $unavailable,
        public ?string $reason,
        public ?string $answer,
        public Collection $cited,
        public Collection $closest,
        public array $applied,
        public ?string $notice,
    ) {}

    public static function unavailable(string $question, string $reason): self
    {
        return new self($question, true, $reason, null, collect(), collect(), [], null);
    }

    /**
     * @param  Collection<int, LibraryEntry>  $cited
     * @param  Collection<int, LibraryEntry>  $closest
     * @param  list<string>  $applied
     */
    public static function answered(string $question, string $answer, Collection $cited, Collection $closest, array $applied): self
    {
        return new self($question, false, null, $answer, $cited, $closest, $applied, null);
    }

    /**
     * @param  Collection<int, LibraryEntry>  $closest
     * @param  list<string>  $applied
     */
    public static function matchesOnly(string $question, Collection $closest, array $applied, string $notice): self
    {
        return new self($question, false, null, null, collect(), $closest, $applied, $notice);
    }

    /**
     * The entries worth listing under the answer: what was cited, else the closest ones.
     *
     * @return Collection<int, LibraryEntry>
     */
    public function shown(): Collection
    {
        return $this->cited->isNotEmpty() ? $this->cited : $this->closest;
    }

    /**
     * The ranked entries the model saw but did not cite. Shown too, smaller,
     * because a model that names one of two matching entries is a model that
     * missed one, and the reader should be able to see that.
     *
     * @return Collection<int, LibraryEntry>
     */
    public function others(): Collection
    {
        if ($this->cited->isEmpty()) {
            return collect();
        }
        $citedIds = $this->cited->pluck('id')->all();

        return $this->closest->reject(fn (LibraryEntry $e) => in_array($e->id, $citedIds, true))->values();
    }
}
