<?php

namespace App\Services\Ai;

use App\Enums\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The part of a question that is not a matter of opinion.
 *
 * "audiobooks I rated 5 last year" has three things in it that a database can
 * answer exactly (format, rating, year) and nothing an embedding model would do
 * better with. This class pulls those out with plain patterns and turns them
 * into WHERE clauses; whatever is left ("the one about a desert planet") is for
 * the embeddings. Deterministic first, probabilistic second, is the rule from
 * TODO.md, and it is also what keeps the answer honest: the model is only ever
 * shown entries that already satisfy the hard constraints.
 *
 * Deliberately small. No author or title parsing (the embeddings handle names
 * well), no relative dates finer than a year, no ranges beyond "at least N".
 * A pattern that misfires would silently hide entries, so each one here is a
 * phrase a person actually types, and each is pinned by a test.
 */
final readonly class LibraryQuestion
{
    /**
     * @param  list<string>  $applied  human-readable descriptions of the filters found
     */
    private function __construct(
        public string $text,
        public ?Format $format,
        public ?int $ratingExact,
        public ?int $ratingMin,
        public bool $unrated,
        public ?int $year,
        public array $applied,
    ) {}

    public static function parse(string $question, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::now();
        $q = ' '.mb_strtolower(trim($question)).' ';
        $applied = [];

        $format = null;
        if (preg_match('/\b(audio ?books?|audio)\b/u', $q)) {
            $format = Format::Audiobook;
        } elseif (preg_match('/\b(e-?books?|ebooks?|kindle)\b/u', $q)) {
            $format = Format::Ebook;
        } elseif (preg_match('/\b(paper ?backs?|hard ?covers?|print(ed)?|physical|paper books?)\b/u', $q)) {
            $format = Format::Book;
        }
        if ($format !== null) {
            $applied[] = strtolower($format->pluralLabel());
        }

        $ratingExact = null;
        $ratingMin = null;
        $unrated = false;

        if (preg_match("/\\b(unrated|not rated|without a rating|no rating|not rate|didn'?t rate|did not rate|haven'?t rated|never rated|no stars)\\b/u", $q)) {
            $unrated = true;
            $applied[] = 'not rated';
        } elseif (($n = self::ratingAfter('at least|minimum of|min|over|more than|or more|or better|or higher', $q))
            ?? ($n = self::ratingBefore('(?:stars?\s*)?(?:\+|or more|or better|or higher|and up|and above|plus)', $q))) {
            // "more than 3" and "over 3" read as "at least 4"; the rest as "at least N".
            $ratingMin = preg_match('/\b(over|more than)\s+(?:a\s+)?(?:[0-5]|zero|one|two|three|four|five)\b/u', $q) ? min(5, $n + 1) : $n;
            $applied[] = "rated {$ratingMin} or more";
        } elseif (($n = self::ratingAfter('rated|rating of|rating|gave|scored', $q))
            ?? ($n = self::ratingBefore('stars?|out of 5|\/\s*5', $q))) {
            $ratingExact = $n;
            $applied[] = "rated {$ratingExact}";
        }

        // A bare four-digit number is not enough: "the 1984 one" is a title, and
        // so is 2001. A year has to sit where a year sits, after "in", "from",
        // "during", "since", or before "reads" / "books" / "list" ("my 2024 reads").
        $year = null;
        if (preg_match('/\b(?:in|during|from|since|back in|of)\s+(?<y>19[5-9]\d|20[0-4]\d)\b|\b(?<y2>19[5-9]\d|20[0-4]\d)\s+(?:reads?|books?|reading|list|library)\b/u', $q, $m)) {
            $year = (int) ($m['y'] !== '' ? $m['y'] : $m['y2']);
        } elseif (preg_match('/\blast year\b/u', $q)) {
            $year = $today->year - 1;
        } elseif (preg_match('/\bthis year\b/u', $q)) {
            $year = $today->year;
        }
        if ($year !== null) {
            $applied[] = "finished in {$year}";
        }

        return new self(trim($question), $format, $ratingExact, $ratingMin, $unrated, $year, $applied);
    }

    private const NUMBER = '(?<n>[0-5]|zero|one|two|three|four|five)';

    private const WORDS = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];

    /** A number 0 to 5, in digits or words, right after one of the phrases. */
    private static function ratingAfter(string $phrases, string $q): ?int
    {
        if (! preg_match('/\b(?:'.$phrases.')\s+(?:a\s+)?'.self::NUMBER.'\b/u', $q, $m)) {
            return null;
        }

        return self::WORDS[$m['n']] ?? (int) $m['n'];
    }

    /** A number 0 to 5, in digits or words, right before one of the phrases ("4 stars", "5+"). */
    private static function ratingBefore(string $phrases, string $q): ?int
    {
        if (! preg_match('/\b'.self::NUMBER.'[ -]?(?:'.$phrases.')/u', $q, $m)) {
            return null;
        }

        return self::WORDS[$m['n']] ?? (int) $m['n'];
    }

    public function hasFilters(): bool
    {
        return $this->applied !== [];
    }

    /**
     * Narrows a read-entry query to what the question pins down.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->format !== null) {
            $query->where('format', $this->format->value);
        }
        if ($this->unrated) {
            $query->whereNull('rating');
        }
        if ($this->ratingExact !== null) {
            $query->where('rating', $this->ratingExact);
        }
        if ($this->ratingMin !== null) {
            $query->where('rating', '>=', $this->ratingMin);
        }
        if ($this->year !== null) {
            // whereYear() is portable: Laravel renders strftime on SQLite and
            // extract() on Postgres.
            $query->whereYear('finished_at', $this->year);
        }

        return $query;
    }
}
