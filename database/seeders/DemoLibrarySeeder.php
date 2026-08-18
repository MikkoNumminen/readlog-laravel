<?php

namespace Database\Seeders;

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a library that is worth looking at the moment `php artisan migrate --seed`
 * finishes: two readers, twelve real books, and enough entries to fill the public
 * feed, the grid and list views, the per-format stats and the "have I read this?"
 * lookup.
 *
 * .NET counterpart: none. readlog-dotnet ships no seed data; the .NET app starts
 * empty and you register an account and search for books. The brief for this
 * migration asks for a demonstrable app straight after seeding, so this is an
 * addition rather than a port.
 *
 * The catalogue rows carry genuine Open Library work keys and cover ids, fetched
 * from openlibrary.org while writing this seeder. That matters: open_library_id is
 * the natural key the log flow uses for find-or-create, so seeded books collide
 * correctly with books found through search instead of being duplicated.
 */
class DemoLibrarySeeder extends Seeder
{
    /**
     * key, title, author, first published, pages, Open Library cover id.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int}>
     */
    private const CATALOGUE = [
        ['/works/OL893414W', 'Dune', 'Frank Herbert', 1965, 606, 11481354],
        ['/works/OL27482W', 'The Hobbit', 'J.R.R. Tolkien', 1937, 310, 14627509],
        ['/works/OL27258W', 'Neuromancer', 'William Gibson', 1984, 317, 283860],
        ['/works/OL59800W', 'The Left Hand of Darkness', 'Ursula K. Le Guin', 1969, 304, 10618463],
        ['/works/OL21745884W', 'Project Hail Mary', 'Andy Weir', 2021, 496, 11200092],
        ['/works/OL38501W', 'Snow Crash', 'Neal Stephenson', 1992, 460, 392508],
        ['/works/OL8479867W', 'The Name of the Wind', 'Patrick Rothfuss', 2007, 736, 11480483],
        ['/works/OL15445697W', 'Kalevala', 'Elias Lönnrot', 1940, 331, 12600036],
        ['/works/OL17075811W', 'Sapiens', 'Yuval Noah Harari', 2011, 456, 8634250],
        ['/works/OL5748544W', 'The Pragmatic Programmer', 'Andy Hunt', 1999, 352, 10143650],
        ['/works/OL15992072W', 'Thinking, fast and slow', 'Daniel Kahneman', 2011, 528, 13290711],
        ['/works/OL20893680W', 'Piranesi', 'Susanna Clarke', 2020, 272, 10226290],
    ];

    /**
     * catalogue index, reader index, format, rating, days since finished.
     *
     * Deliberately uneven: one reader has most of the library, the other has a
     * handful, and both have logged Dune, which is what makes the shared-catalogue
     * behaviour visible (one books row, two read_entries rows).
     *
     * @var list<array{0: int, 1: int, 2: Format, 3: int|null, 4: int}>
     */
    private const ENTRIES = [
        [0, 0, Format::Book, 5, 12],
        [1, 0, Format::Audiobook, 4, 25],
        [2, 0, Format::Ebook, 4, 40],
        [3, 0, Format::Book, 5, 58],
        [4, 0, Format::Audiobook, 5, 74],
        [5, 0, Format::Book, 3, 96],
        [6, 0, Format::Ebook, null, 130],
        [7, 0, Format::Book, 2, 190],
        [8, 0, Format::Audiobook, 4, 240],
        [9, 0, Format::Ebook, 5, 300],
        [0, 1, Format::Audiobook, 4, 8],
        [10, 1, Format::Book, 3, 33],
        [11, 1, Format::Ebook, 5, 61],
        [4, 1, Format::Book, 0, 110],
    ];

    public function run(): void
    {
        $readers = [
            $this->reader('Mikko', 'mikko@example.com'),
            $this->reader('Sam Reader', 'sam@example.com'),
        ];

        $books = [];
        foreach (self::CATALOGUE as [$key, $title, $author, $year, $pages, $coverId]) {
            // updateOrCreate keyed on open_library_id makes the seeder idempotent, so
            // running `db:seed` twice does not duplicate the catalogue.
            $books[] = Book::updateOrCreate(
                ['open_library_id' => $key],
                [
                    'title' => $title,
                    'author' => $author,
                    'cover_url' => "https://covers.openlibrary.org/b/id/{$coverId}-M.jpg",
                    'page_count' => $pages,
                    'first_publish_year' => $year,
                ],
            );
        }

        foreach (self::ENTRIES as $i => [$bookIndex, $readerIndex, $format, $rating, $daysAgo]) {
            $entry = ReadEntry::firstOrNew([
                'user_id' => $readers[$readerIndex]->id,
                'book_id' => $books[$bookIndex]->id,
                'finished_at' => Carbon::today()->subDays($daysAgo)->toDateString(),
            ]);

            $entry->format = $format;
            $entry->rating = $rating;

            // The public feed orders by created_at, so stamp it explicitly rather than
            // letting every seeded row share one timestamp. Eloquent only fills
            // created_at when it is not already dirty, so this assignment survives save().
            $entry->created_at = Carbon::today()->subDays($daysAgo)->addHours(20)->addMinutes($i);
            $entry->save();
        }
    }

    private function reader(string $name, string $email): User
    {
        // Assigned property by property rather than through updateOrCreate, on purpose.
        // email_verified_at is not on the User mass-assignment allowlist, so fill()
        // would drop it without a word. `artisan db:seed` happens to run models
        // unguarded, which would hide that, but a seeder should not depend on the
        // caller having switched the guard off.
        $user = User::firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->email_verified_at = now();
        // Version 1 has no login, so this hash is never checked. It is set so that the
        // column is valid the day authentication is added.
        $user->password = Hash::make('password');
        $user->save();

        return $user;
    }
}
