<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| .NET counterpart: the "ReadEntries" table in
| Data/Migrations/20260621170708_InitialCreate.cs, configured in
| ApplicationDbContext.OnModelCreating.
|
| One deliberate gap: the .NET schema carries a check constraint,
| CK_ReadEntry_Rating ([Rating] IS NULL OR ([Rating] >= 0 AND [Rating] <= 5)).
| Laravel's schema builder has no check-constraint API, and SQLite cannot add one
| to an existing table, so the 0-5 bound is enforced in request validation and in
| the model instead. This is a real loss of a database-level guarantee and it is
| written up as such in MIGRATION.md rather than glossed over.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('read_entries', function (Blueprint $table) {
            $table->id();

            // Deleting a user removes their entries; a book that entries point at
            // cannot be deleted. Same pair as the .NET DeleteBehavior.Cascade /
            // DeleteBehavior.Restrict, which in turn mirrors the original Prisma schema.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();

            // Stored as the readable name ("Book" / "Audiobook" / "Ebook"), not an
            // ordinal. .NET does this with HasConversion<string>().HasMaxLength(16);
            // here the PHP backed enum's string values are the stored values.
            $table->string('format', 16);

            // Date only, no time component (.NET DateOnly).
            $table->date('finished_at');

            // Optional 0-5 rating. Null means "no rating"; 0 is a real value, so the
            // column must stay nullable rather than defaulting to 0.
            $table->integer('rating')->nullable();

            $table->timestamp('created_at');

            // One read per (user, book, finished-on date).
            $table->unique(['user_id', 'book_id', 'finished_at']);
            $table->index('user_id');
            $table->index('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('read_entries');
    }
};
