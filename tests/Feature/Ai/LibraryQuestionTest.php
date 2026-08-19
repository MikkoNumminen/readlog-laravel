<?php

use App\Enums\Format;
use App\Services\Ai\LibraryQuestion;
use Carbon\CarbonImmutable;

/*
| The deterministic layer of "ask your library". Every pattern here is a phrase
| a person types; each is pinned so a later "improvement" cannot start hiding
| entries silently.
*/

function parsed(string $q): LibraryQuestion
{
    return LibraryQuestion::parse($q, CarbonImmutable::parse('2026-08-19'));
}

it('finds the format', function (string $q, Format $format, string $label) {
    $p = parsed($q);
    expect($p->format)->toBe($format)->and($p->applied)->toContain($label);
})->with([
    ['audiobooks I loved', Format::Audiobook, 'audiobooks'],
    ['the audio book about whales', Format::Audiobook, 'audiobooks'],
    ['kindle reads', Format::Ebook, 'e-books'],
    ['my e-books', Format::Ebook, 'e-books'],
    ['ebook from 2024', Format::Ebook, 'e-books'],
    ['paperbacks', Format::Book, 'books'],
    ['physical books only', Format::Book, 'books'],
]);

it('does not read "books" or "printed" alone as the print format', function (string $q) {
    expect(parsed($q)->format)->toBeNull();
})->with(['books about dragons', 'when was dune first printed', 'the print run of 1965']);

it('finds an exact rating in digits or words', function (string $q, int $rating) {
    $p = parsed($q);
    expect($p->ratingExact)->toBe($rating)->and($p->ratingMin)->toBeNull()->and($p->applied)->toContain("rated {$rating}");
})->with([
    ['audiobooks I rated 5 last year', 5],
    ['anything I gave five stars', 5],
    ['my 2 star reads', 2],
    ['5/5 books', 5],
    ['rating of 3', 3],
    ['4 out of 5', 4],
    ['rated 0', 0],
    ['zero stars', 0],
]);

it('does not read "starts" or "starring" as stars', function (string $q) {
    $p = parsed($q);
    expect($p->ratingExact)->toBeNull()->and($p->ratingMin)->toBeNull();
})->with(['which one starts with a shipwreck', 'the one starring a detective', 'at least one starting point']);

it('finds a minimum rating', function (string $q, int $min) {
    $p = parsed($q);
    expect($p->ratingMin)->toBe($min)->and($p->ratingExact)->toBeNull()->and($p->applied)->toContain("rated {$min} or more");
})->with([
    ['books with 4 stars or more', 4],
    ['at least 4 stars', 4],
    ['rated at least 4', 4],
    ['3+ audio', 3],
    ['what did I read over 3 stars', 4],
    ['more than two stars', 3],
    ['4 or better', 4],
    ['1 or more', 1],
]);

it('does not read a count of things as a rating', function (string $q) {
    $p = parsed($q);
    expect($p->ratingMin)->toBeNull()->and($p->ratingExact)->toBeNull()->and($p->unrated)->toBeFalse();
})->with([
    'at least 4 books',
    'the 2 books I read over 3 weeks',
    'authors I read one or more times',
    'more than two audiobooks',
    'the 3 audiobooks from june',
]);

it('finds unrated', function (string $q) {
    expect(parsed($q)->unrated)->toBeTrue()->and(parsed($q)->applied)->toContain('not rated');
})->with(['unrated kindle books', 'which books did I not rate', "the ones I didn't rate", 'books with no rating', 'anything I never rated']);

it('combines format and unrated', function () {
    $p = parsed('unrated kindle books');
    expect($p->format)->toBe(Format::Ebook)->and($p->applied)->toBe(['e-books', 'not rated']);
});

it('reads "since" as a lower bound, not a year', function () {
    $p = parsed('books I read since 2023');
    expect($p->yearFrom)->toBe(2023)->and($p->year)->toBeNull()->and($p->applied)->toBe(['finished since 2023']);
});

it('finds a year only where a year sits', function (string $q, ?int $year) {
    expect(parsed($q)->year)->toBe($year);
})->with([
    ['anything I gave five stars in 2024', 2024],
    ['books from 2023', 2023],
    ['my 2024 reads', 2024],
    ['what did I finish in 2025?', 2025],
    ['last year', 2025],
    ['this year', 2026],
    ['the 1984 one', null],
    ['2001 a space odyssey', null],
]);

it('leaves a question with nothing exact in it alone', function () {
    $p = parsed('something by le guin about a desert planet');
    expect($p->hasFilters())->toBeFalse()->and($p->applied)->toBe([])->and($p->text)->toBe('something by le guin about a desert planet');
});
