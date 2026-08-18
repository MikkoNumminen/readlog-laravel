<?php

namespace Database\Factories;

use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * .NET counterpart: none. The .NET tests build entities by hand in each test
 * (tests/ReadLog.Tests/Services/ReadLogServiceTests.cs). Laravel's factories are
 * the closest thing to an object-mother, and Pest tests lean on them heavily,
 * so the port gains one where the source had none.
 *
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->sentence(3), '.'),
            'author' => fake()->name(),
            'cover_url' => null,
            // Namespaced like a real provider id so find-or-create behaves the same
            // way it does for a book that came out of a search.
            'open_library_id' => 'manual:'.Str::random(16),
            'page_count' => fake()->numberBetween(80, 900),
            'first_publish_year' => fake()->numberBetween(1900, 2025),
        ];
    }
}
