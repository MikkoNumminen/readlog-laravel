<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * .NET counterpart: none in readlog-dotnet. The nearest .NET idiom would be a
 * hosted service or an `IDbInitializer` invoked from Program.cs after
 * Database.Migrate(); Laravel makes seeding a first-class artisan verb instead.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(DemoLibrarySeeder::class);
    }
}
