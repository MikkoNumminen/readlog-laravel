<?php

use App\Models\ReadEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| The custom cast that stands in for .NET's DateOnly. The point of these tests is
| the stored representation: `2024-03-03`, not `2024-03-03 00:00:00`, so that the
| unique index and the find-or-create lookups can be matched with the plain date
| string that comes off an HTML date input.
*/

it('writes a bare Y-m-d string to the column', function (mixed $input) {
    ReadEntry::factory()->create(['finished_at' => $input]);

    expect(DB::table('read_entries')->value('finished_at'))->toBe('2024-03-03');
})->with([
    'a date string' => '2024-03-03',
    'a Carbon instance' => fn () => CarbonImmutable::parse('2024-03-03 14:22:07'),
    'a native DateTime' => fn () => new DateTime('2024-03-03 23:59:59'),
]);

it('reads back as an immutable instant at midnight', function () {
    $entry = ReadEntry::factory()->create(['finished_at' => '2024-03-03']);

    $value = $entry->fresh()->finished_at;

    expect($value)->toBeInstanceOf(CarbonImmutable::class)
        ->and($value->toDateTimeString())->toBe('2024-03-03 00:00:00');
});

it('does not mutate the attribute when the read value is advanced', function () {
    $entry = ReadEntry::factory()->create(['finished_at' => '2024-03-03']);

    $entry->finished_at->addDay();

    expect($entry->finished_at->toDateString())->toBe('2024-03-03');
});

it('matches a plain date string in a where clause', function () {
    $entry = ReadEntry::factory()->create(['finished_at' => '2024-03-03']);

    $found = ReadEntry::where('user_id', $entry->user_id)
        ->where('book_id', $entry->book_id)
        ->where('finished_at', '2024-03-03')
        ->first();

    expect($found?->id)->toBe($entry->id);
});
