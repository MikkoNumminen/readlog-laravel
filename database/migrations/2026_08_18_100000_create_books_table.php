<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| .NET counterpart: Data/Migrations/20260621170708_InitialCreate.cs, the "Books"
| table, whose shape comes from Models/Book.cs plus the fluent configuration in
| ApplicationDbContext.OnModelCreating.
|
| Where EF Core generates the migration by diffing the C# entity model against the
| last snapshot, Laravel migrations are hand-written: this file IS the schema, and
| the Eloquent model carries no schema information at all. That is the practical
| face of data mapper vs Active Record. See MIGRATION.md.
*/
return new class extends Migration
{
    public function up(): void
    {
        // The shared catalogue: one row per real-world book, reused across every
        // user's read entries.
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('cover_url')->nullable();

            // Provider id used as the natural key for find-or-create. Holds an Open
            // Library work key (/works/OL1W), a google:<id>, or a manual:<token> for
            // hand-entered books. Unique, nullable, exactly as in the .NET model.
            $table->string('open_library_id')->nullable()->unique();

            $table->integer('page_count')->nullable();
            $table->integer('first_publish_year')->nullable();

            // Only created_at, no updated_at: the .NET Book has a single CreatedAt
            // (Models/ICreatedAt.cs). The model sets `const UPDATED_AT = null` so
            // Eloquent does not look for the column Laravel would normally expect.
            $table->timestamp('created_at');

            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
