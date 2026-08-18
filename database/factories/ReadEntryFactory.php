<?php

namespace Database\Factories;

use App\Enums\Format;
use App\Models\Book;
use App\Models\ReadEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadEntry>
 */
class ReadEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'format' => Format::Book,
            'finished_at' => fake()->dateTimeBetween('-2 years', 'today')->format('Y-m-d'),
            'rating' => fake()->numberBetween(0, 5),
        ];
    }
}
