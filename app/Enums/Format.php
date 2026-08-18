<?php

namespace App\Enums;

/**
 * How a book was consumed. Persisted as its string name.
 *
 * .NET counterpart: Models/Format.cs plus the extension methods in
 * Models/FormatDisplay.cs. C# splits the two because an enum cannot carry
 * behaviour; a PHP backed enum can, so the display helpers live on the enum
 * itself instead of on a static extension class.
 *
 * The case values are the strings the .NET app stores via
 * `HasConversion<string>()`, so a database from either app reads in the other.
 */
enum Format: string
{
    case Book = 'Book';
    case Audiobook = 'Audiobook';
    case Ebook = 'Ebook';

    public function label(): string
    {
        return match ($this) {
            self::Audiobook => 'Audiobook',
            self::Ebook => 'E-book',
            self::Book => 'Book',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Audiobook => 'Audiobooks',
            self::Ebook => 'E-books',
            self::Book => 'Books',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Audiobook => '🎧',
            self::Ebook => '📱',
            self::Book => '📖',
        };
    }
}
