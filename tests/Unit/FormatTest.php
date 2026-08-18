<?php

use App\Enums\Format;

/*
| .NET counterpart: the extension methods in Models/FormatDisplay.cs, which the
| .NET suite exercises only indirectly through the rendered pages. Here they are
| pinned directly, because the stored string values are also the wire format
| shared with the .NET database.
*/

it('stores each case as the readable name the .NET app writes', function () {
    expect(Format::Book->value)->toBe('Book')
        ->and(Format::Audiobook->value)->toBe('Audiobook')
        ->and(Format::Ebook->value)->toBe('Ebook');
});

it('has exactly the three cases the source app defines', function () {
    expect(array_map(fn (Format $f) => $f->value, Format::cases()))
        ->toBe(['Book', 'Audiobook', 'Ebook']);
});

it('renders the display labels from FormatDisplay.cs', function (Format $format, string $label, string $plural, string $icon) {
    expect($format->label())->toBe($label)
        ->and($format->pluralLabel())->toBe($plural)
        ->and($format->icon())->toBe($icon);
})->with([
    [Format::Book, 'Book', 'Books', '📖'],
    [Format::Audiobook, 'Audiobook', 'Audiobooks', '🎧'],
    [Format::Ebook, 'E-book', 'E-books', '📱'],
]);

it('round-trips a stored value back to its case', function () {
    expect(Format::from('Ebook'))->toBe(Format::Ebook)
        ->and(Format::tryFrom('Hardback'))->toBeNull();
});
