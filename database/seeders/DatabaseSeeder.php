<?php

namespace Database\Seeders;

use App\Models\Book;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * .NET counterpart: none in readlog-dotnet. The nearest .NET idiom would be a
 * hosted service or an `IDbInitializer` invoked from Program.cs after
 * Database.Migrate(); Laravel makes seeding a first-class artisan verb instead.
 *
 * This is the entry point `php artisan db:seed` and `migrate --seed` run, and it is
 * also what runs on every deploy (see MANUAL-STEPS.md). Because of that second
 * role it seeds the demo library only into an empty catalogue. DemoLibrarySeeder
 * itself is idempotent, so re-running it would not duplicate anything, but it
 * would recreate any demo entry the author had deliberately deleted from a live
 * instance. Skipping when books already exist means the demo data appears once,
 * on the first deploy, and is the author's to keep or remove after that.
 *
 * To force the demo library into a populated database, call the seeder directly:
 *
 *     php artisan db:seed --class=DemoLibrarySeeder
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (Book::query()->exists()) {
            $this->command->info('Catalogue already populated; skipping the demo library. '
                .'Run "php artisan db:seed --class=DemoLibrarySeeder" to seed it anyway.');

            return;
        }

        $this->call(DemoLibrarySeeder::class);
    }
}
